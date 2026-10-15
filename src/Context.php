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
        private ?self $parent,
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
            parent: null,
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

        $nowNs = $this->timeSource->nowNs();
        // round(), not (int): 1.015 * 1e9 is 1014999999.99…, and truncating
        // made remainingMs() report 1014 right after asking for 1015.
        $durationNs = round(max(0.0, $seconds) * 1_000_000_000.0);
        $roomNs = \PHP_INT_MAX - $nowNs;
        $deadline = $durationNs >= (float) $roomNs ? \PHP_INT_MAX : $nowNs + (int) $durationNs;

        return $this->withDeadline($deadline);
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

        // round(), not (int): 0.892 * 1e9 is 891999999.99…, and truncating
        // would leave the deadline a nanosecond later than asked.
        $reserveNs = round($seconds * 1_000_000_000.0);
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
            parent: null,
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

    /** @param class-string $key */
    public function withValue(string $key, object $value): self
    {
        if (!$value instanceof $key) {
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
        $this->observe();
        $cause = $this->state->cause();

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

    public function isTerminated(): bool
    {
        $this->observe();

        return $this->state->cause() instanceof \Rasuvaeff\Context\TerminationCause;
    }

    public function isCancelled(): bool
    {
        $this->observe();

        return $this->state->cause()?->isCancelled() ?? false;
    }

    public function isExpired(): bool
    {
        $this->observe();

        return $this->state->cause()?->isExpired() ?? false;
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
        $durationNs = round(max(0.0, $seconds) * 1_000_000_000.0);
        $endNs = $durationNs >= (float) (\PHP_INT_MAX - $nowNs) ? \PHP_INT_MAX : $nowNs + (int) $durationNs;
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
    public function iterate(iterable $items, int $every = 1): \Generator
    {
        if ($every < 1) {
            throw new \InvalidArgumentException('Check interval must be positive');
        }

        return $this->iterateChecked($items, $every);
    }

    /** @param class-string $key */
    public function value(string $key): ?object
    {
        return $this->values[$key] ?? $this->parent?->value($key);
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
            yield $iterator->key() => $iterator->current();

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
            parent: $this,
            timeSource: $this->timeSource,
            sleeper: $this->sleeper,
            deadlineNs: $deadlineNs,
            deadlineOwner: $deadlineOwner,
            values: $values ?? $this->values,
        );
    }
}
