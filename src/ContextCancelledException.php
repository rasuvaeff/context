<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/** @api */
final class ContextCancelledException extends ContextException
{
    public function __construct(?\Throwable $reason = null)
    {
        parent::__construct('Context was cancelled', previous: $reason);
    }
}
