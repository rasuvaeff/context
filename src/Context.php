<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

use Rasuvaeff\Context\Internal\ContextState;

/**
 * @api
 */
final readonly class Context
{
    private function __construct(
        private ContextState $state,
        private TimeSource $timeSource,
        private Sleeper $sleeper,
        private ?int $deadlineNs,
        private ?ContextState $deadlineOwner,
        /** @var array<class-string, object> */
        private array $values,
    ) {}

    /**
     * A `$timeSource` that also implements {@see Sleeper} (such as
     * {@see Testing\FakeTimeSource}) is used as the sleeper when none is given.
     */
    public static function background(?TimeSource $timeSource = null, ?Sleeper $sleeper = null): self
    {
        $timeSource ??= new HrTimeSource();

        return new self(
            state: new ContextState(),
            timeSource: $timeSource,
            sleeper: $sleeper ?? ($timeSource instanceof Sleeper ? $timeSource : new UsleepSleeper()),
            deadlineNs: null,
            deadlineOwner: null,
            values: [],
        );
    }

    /** @return array{self, ContextController} */
    public function withCancel(): array
    {
        $state = new ContextState();
        $context = $this->derive(state: $state, deadlineNs: $this->deadlineNs, deadlineOwner: $this->deadlineOwner);

        return [$context, new ContextController($state)];
    }

    /**
     * A zero or negative timeout yields an already-expired context; a
     * timeout beyond the representable range saturates at `PHP_INT_MAX`
     * nanoseconds instead of overflowing into an immediate deadline.
     *
     * @return array{self, ContextController}
     */
    public function withTimeout(float $seconds): array
    {
        if (!is_finite($seconds)) {
            throw new \InvalidArgumentException('Timeout must be finite');
        }

        return $this->withDeadline($this->later($this->timeSource->nowNs(), $seconds));
    }

    /** @return array{self, ContextController} */
    public function withDeadline(int $absoluteNs): array
    {
        $state = new ContextState();
        [$deadline, $owner] = $this->earlierDeadline($absoluteNs, $state);
        $context = $this->derive(state: $state, deadlineNs: $deadline, deadlineOwner: $owner);

        return [$context, new ContextController($state)];
    }

    /**
     * The parent's deadline moved earlier by `$seconds`, leaving time to act
     * after lower layers return (answer 504, NACK a message). Without a
     * parent deadline this is {@see withCancel()}; a reserve that covers the
     * whole remaining budget yields an already-expired context.
     *
     * @return array{self, ContextController}
     */
    public function withReserve(float $seconds): array
    {
        if (!is_finite($seconds) || $seconds < 0.0) {
            throw new \InvalidArgumentException('Reserve must be a finite non-negative number of seconds');
        }

        if ($this->deadlineNs === null) {
            return $this->withCancel();
        }

        $reserveNs = $this->nanoseconds($seconds);
        $deadline = $reserveNs >= (float) $this->deadlineNs ? 0 : $this->deadlineNs - (int) $reserveNs;

        return $this->withDeadline($deadline);
    }

    /**
     * Keeps this context's values but neither its deadline nor its
     * cancellation: for work that must outlive the request (audit,
     * compensation, error logging). Bound it with `withTimeout()`.
     */
    public function withoutCancel(): self
    {
        // No parent link at all: values are copied, so a long-running cleanup
        // does not keep the request's context tree alive.
        return new self(
            state: new ContextState(),
            timeSource: $this->timeSource,
            sleeper: $this->sleeper,
            deadlineNs: null,
            deadlineOwner: null,
            values: $this->values,
        );
    }

    /**
     * Terminates when either this context or `$other` does (the first cause
     * wins); the deadline is the earlier of the two. Values come from this
     * context only. When both belong to one tree, derive instead.
     *
     * @return array{self, ContextController}
     */
    public function joinedWith(self $other): array
    {
        if (!$this->sharesClockWith($other)) {
            throw new \InvalidArgumentException('Joined contexts must share a time source');
        }

        $state = new ContextState();
        $deadline = $this->deadlineNs;
        $owner = $this->deadlineOwner;
        if ($other->deadlineNs !== null && ($deadline === null || $other->deadlineNs < $deadline)) {
            $deadline = $other->deadlineNs;
            $owner = $other->deadlineOwner;
        }

        $context = $this->derive(state: $state, deadlineNs: $deadline, deadlineOwner: $owner);
        $other->state->addChild($state);

        return [$context, new ContextController($state)];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $key
     * @param T $value
     */
    public function withValue(string $key, object $value): self
    {
        if (!$this->matchesKey($value, $key)) {
            throw new \InvalidArgumentException('Context value does not match its key');
        }

        $values = $this->values;
        $values[$key] = $value;

        return $this->derive(
            state: $this->state,
            deadlineNs: $this->deadlineNs,
            deadlineOwner: $this->deadlineOwner,
            values: $values,
        );
    }

    public function assertActive(): void
    {
        $cause = $this->cause();

        if ($cause instanceof TerminationCause) {
            if ($cause->isCancelled()) {
                throw new ContextCancelledException($cause->reason());
            }

            if ($cause->isExpired()) {
                $deadlineNs = $cause->deadlineNs();
                if ($deadlineNs === null) {
                    throw new \LogicException('Expired context has no deadline');
                }

                throw new ContextExpiredException($deadlineNs);
            }
        }
    }

    /**
     * Why this context terminated, or `null` while it is active. Observes
     * pending cancellation and the deadline like the other checks.
     */
    public function cause(): ?TerminationCause
    {
        $this->observe();

        return $this->state->cause();
    }

    public function isTerminated(): bool
    {
        return $this->cause() instanceof TerminationCause;
    }

    public function isCancelled(): bool
    {
        return $this->cause()?->isCancelled() ?? false;
    }

    public function isExpired(): bool
    {
        return $this->cause()?->isExpired() ?? false;
    }

    public function remainingMs(): ?float
    {
        $this->observe();

        if ($this->deadlineNs === null) {
            return null;
        }

        if ($this->state->cause()?->isExpired()) {
            return 0.0;
        }

        $remainingNs = $this->deadlineNs - $this->timeSource->nowNs();
        $milliseconds = floor($remainingNs / 1_000_000);

        return $milliseconds > 0 ? $milliseconds : 1.0;
    }

    /**
     * The remaining budget in whole milliseconds, at least 1, or `null`
     * without a deadline: what an adapter hands to a lower layer (a header,
     * a statement timeout). The budget is read before the check, so a value
     * returned is never the `0` of an expired context.
     *
     * @return positive-int|null
     *
     * @throws ContextException when the context is terminated
     */
    public function budgetMs(): ?int
    {
        $remainingMs = $this->remainingMs();
        $this->assertActive();

        return $remainingMs === null ? null : max(1, (int) $remainingMs);
    }

    public function deadlineNs(): ?int
    {
        return $this->deadlineNs;
    }

    /**
     * Sleeps for `$seconds`, but never past the deadline: throws
     * {@see ContextExpiredException} when the deadline comes first and
     * {@see ContextCancelledException} when the context is cancelled (a
     * signal wakes the sleep early). Throws at once on a terminated context.
     */
    public function sleep(float $seconds): void
    {
        if (!is_finite($seconds)) {
            throw new \InvalidArgumentException('Sleep duration must be finite');
        }

        $this->assertActive();
        $nowNs = $this->timeSource->nowNs();
        $endNs = $this->later($nowNs, $seconds);
        $targetNs = $this->deadlineNs === null ? $endNs : min($endNs, $this->deadlineNs);

        // A sleeper may return early: re-check the clock until the target.
        while ($nowNs < $targetNs) {
            $this->sleeper->sleepNs($targetNs - $nowNs);
            $this->assertActive();
            $nowNs = $this->timeSource->nowNs();
        }
    }

    /**
     * Yields `$items` with their keys, checking the context before pulling
     * each element (every `$every` elements), so a generator source is never
     * advanced after the context terminates.
     *
     * @template TKey
     * @template TValue
     *
     * @param iterable<TKey, TValue> $items
     *
     * @return \Generator<TKey, TValue, mixed, void>
     */
    public function iterate(
        iterable $items,
        int $every = 1,
    ): \Generator {
        if ($every < 1) {
            throw new \InvalidArgumentException('Check interval must be positive');
        }

        return $this->iterateChecked($items, $every);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $key
     *
     * @psalm-return T|null
     */
    public function value(string $key): ?object
    {
        $value = $this->values[$key] ?? null;

        return $value instanceof $key ? $value : null;
    }

    /**
     * Runs `$listener` once when this context terminates (immediately if it
     * already has). The returned closure unregisters it — call it when the
     * guarded resource is released before the context ends, so a
     * long-lived context does not keep every listener alive.
     *
     * @param callable(TerminationCause): void $listener
     *
     * @return \Closure(): void
     */
    public function onTerminate(callable $listener): \Closure
    {
        return $this->state->addListener(\Closure::fromCallable($listener));
    }

    /**
     * @template TKey
     * @template TValue
     *
     * @param iterable<TKey, TValue> $items
     * @param positive-int $every
     *
     * @return \Generator<TKey, TValue, mixed, void>
     */
    private function iterateChecked(iterable $items, int $every): \Generator
    {
        $iterator = \is_array($items) ? new \ArrayIterator($items) : new \IteratorIterator($items);

        $this->assertActive();
        $iterator->rewind();
        $untilCheck = $every;

        while ($iterator->valid()) {
            /** @var TKey $key valid() is true, so key() is not the past-the-end null */
            $key = $iterator->key();
            /** @var TValue $value */
            $value = $iterator->current();

            yield $key => $value;

            if (--$untilCheck === 0) {
                $this->assertActive();
                $untilCheck = $every;
            }
            $iterator->next();
        }
    }

    /**
     * Requested cancellations first (an explicit request wins over a deadline
     * discovered at the same check), then the lazy deadline.
     */
    private function observe(): void
    {
        $this->state->resolvePending();

        if ($this->deadlineNs === null || $this->timeSource->nowNs() < $this->deadlineNs) {
            return;
        }

        // A deadline always has an owner; terminating an already terminated
        // owner is a no-op.
        $this->deadlineOwner?->terminate(TerminationCause::expired($this->deadlineNs));
    }

    /**
     * An equal deadline is already owned by the parent: keeping that owner
     * preserves the rule that observing a child discovers and terminates the
     * ancestor's complete subtree.
     *
     * @return array{int, ContextState|null}
     */
    private function earlierDeadline(int $absoluteNs, ContextState $state): array
    {
        if ($this->deadlineNs === null || $absoluteNs < $this->deadlineNs) {
            return [$absoluteNs, $state];
        }

        return [$this->deadlineNs, $this->deadlineOwner];
    }

    /**
     * `$fromNs` plus `$seconds` (a negative duration counts as zero),
     * saturating at `PHP_INT_MAX` instead of overflowing.
     */
    private function later(int $fromNs, float $seconds): int
    {
        $durationNs = $this->nanoseconds($seconds);

        return $durationNs >= (float) (\PHP_INT_MAX - $fromNs) ? \PHP_INT_MAX : $fromNs + (int) $durationNs;
    }

    /**
     * round(), not (int): 1.015 * 1e9 is 1014999999.99…, and truncating made
     * remainingMs() report 1014 right after asking for 1015.
     */
    private function nanoseconds(float $seconds): float
    {
        return round(max(0.0, $seconds) * 1_000_000_000.0);
    }

    /**
     * The generics bind only callers that run psalm; this keeps the runtime
     * guard for the rest, out of reach of psalm's "always true" verdict.
     */
    private function matchesKey(object $value, string $key): bool
    {
        return $value instanceof $key;
    }

    private function sharesClockWith(self $other): bool
    {
        return $this->timeSource === $other->timeSource
            || ($this->timeSource instanceof HrTimeSource && $other->timeSource instanceof HrTimeSource);
    }

    /**
     * A value-only derivation shares its parent's state: it has no
     * controller of its own, so it terminates exactly when the parent does,
     * and the parent does not retain one child per withValue().
     *
     * @param array<class-string, object>|null $values
     */
    private function derive(
        ContextState $state,
        ?int $deadlineNs,
        ?ContextState $deadlineOwner,
        /** @var array<class-string, object>|null $values */
        ?array $values = null,
    ): self {
        if ($state !== $this->state) {
            $this->state->addChild($state);
        }

        return new self(
            state: $state,
            timeSource: $this->timeSource,
            sleeper: $this->sleeper,
            deadlineNs: $deadlineNs,
            deadlineOwner: $deadlineOwner,
            values: $values ?? $this->values,
        );
    }
}
