<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

use Rasuvaeff\Context\Internal\ContextState;

/**
 * @api
 */
final readonly class ContextController
{
    public function __construct(private ContextState $state) {}

    /**
     * Terminates now: listeners and descendants run synchronously, and a
     * listener failure is rethrown from here once the cascade completes.
     */
    public function cancel(?\Throwable $reason = null): void
    {
        $this->state->terminate(TerminationCause::cancelled($reason));
    }

    /**
     * Records the cancellation without running anything; the next check of
     * this context or a descendant terminates it, and listener failures
     * surface from that check. Use it from signal handlers, where `cancel()`
     * would run listeners at an arbitrary point of the interrupted code.
     */
    public function requestCancel(?\Throwable $reason = null): void
    {
        $this->state->requestTermination(TerminationCause::cancelled($reason));
    }
}
