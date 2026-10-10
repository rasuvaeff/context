<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

use Rasuvaeff\Context\Internal\ContextState;

/** @api */
final readonly class Context
{
    private function __construct(
        private ContextState $state,
        private ?self $parent,
        private TimeSource $timeSource,
        private ?int $deadlineNs,
        private ?ContextState $deadlineOwner,
        /** @var array<class-string, object> */
        private array $values,
    ) {
        $parent?->state->addChild($state);
    }

    public static function background(?TimeSource $timeSource = null): self
    {
        return new self(
            state: new ContextState(),
            parent: null,
            timeSource: $timeSource ?? new HrTimeSource(),
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

    /** @return array{self, ContextController} */
    public function withTimeout(float $seconds): array
    {
        if (!is_finite($seconds)) {
            throw new \InvalidArgumentException('Timeout must be finite');
        }

        $durationNs = (int) ($seconds * 1_000_000_000.0);
        $deadline = $this->timeSource->nowNs() + $durationNs;

        return $this->withDeadline($deadline);
    }

    /** @return array{self, ContextController} */
    public function withDeadline(int $absoluteNs): array
    {
        $state = new ContextState();
        $deadline = $this->deadlineNs === null ? $absoluteNs : min($this->deadlineNs, $absoluteNs);
        // An equal deadline is already owned by the parent. Keeping that
        // owner preserves the rule that observing a child discovers and
        // terminates the ancestor's complete subtree.
        $owner = $this->deadlineNs === null || $absoluteNs < $this->deadlineNs
            ? $state
            : $this->deadlineOwner;
        $context = $this->derive(state: $state, deadlineNs: $deadline, deadlineOwner: $owner);

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
            state: new ContextState(),
            deadlineNs: $this->deadlineNs,
            deadlineOwner: $this->deadlineOwner,
            values: $values,
        );
    }

    public function assertActive(): void
    {
        $this->observeDeadline();
        $cause = $this->state->cause();

        if ($cause !== null) {
            if ($cause->isCancelled()) {
                throw new ContextCancelledException($cause->reason());
            }

            if ($cause->isExpired()) {
                throw new ContextExpiredException($cause->deadlineNs() ?? 0);
            }
        }
    }

    public function isTerminated(): bool
    {
        $this->observeDeadline();

        return $this->state->cause() instanceof \Rasuvaeff\Context\TerminationCause;
    }

    public function isCancelled(): bool
    {
        $this->observeDeadline();

        return $this->state->cause()?->isCancelled() ?? false;
    }

    public function isExpired(): bool
    {
        $this->observeDeadline();

        return $this->state->cause()?->isExpired() ?? false;
    }

    public function remainingMs(): ?float
    {
        $this->observeDeadline();

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

    /** @param class-string $key */
    public function value(string $key): ?object
    {
        return $this->values[$key] ?? $this->parent?->value($key);
    }

    /** @param callable(TerminationCause): void $listener */
    public function onTerminate(callable $listener): void
    {
        $this->state->addListener(\Closure::fromCallable($listener));
    }

    private function observeDeadline(): void
    {
        if ($this->deadlineNs === null || $this->timeSource->nowNs() < $this->deadlineNs) {
            return;
        }

        $owner = $this->deadlineOwner;
        if (!$owner instanceof \Rasuvaeff\Context\Internal\ContextState || $owner->cause() instanceof \Rasuvaeff\Context\TerminationCause) {
            return;
        }

        $owner->terminate(TerminationCause::expired($this->deadlineNs));
    }

    /**
     * @param array<class-string, object>|null $values
     */
    private function derive(
        ContextState $state,
        ?int $deadlineNs,
        ?ContextState $deadlineOwner,
        /** @var array<class-string, object>|null $values */
        ?array $values = null,
    ): self {
        return new self(
            state: $state,
            parent: $this,
            timeSource: $this->timeSource,
            deadlineNs: $deadlineNs,
            deadlineOwner: $deadlineOwner,
            values: $values ?? $this->values,
        );
    }
}
