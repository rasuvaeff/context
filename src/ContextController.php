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

    public function cancel(?\Throwable $reason = null): void
    {
        $this->state->terminate(TerminationCause::cancelled($reason));
    }
}
