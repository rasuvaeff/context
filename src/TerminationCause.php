<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/** @api */
final readonly class TerminationCause
{
    private function __construct(
        private bool $cancelled,
        private ?\Throwable $reason,
        private ?int $deadlineNs,
    ) {}

    public static function cancelled(?\Throwable $reason = null): self
    {
        return new self(cancelled: true, reason: $reason, deadlineNs: null);
    }

    public static function expired(int $deadlineNs): self
    {
        return new self(cancelled: false, reason: null, deadlineNs: $deadlineNs);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function isExpired(): bool
    {
        return !$this->cancelled;
    }

    public function reason(): ?\Throwable
    {
        return $this->reason;
    }

    public function deadlineNs(): ?int
    {
        return $this->deadlineNs;
    }
}
