<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/** @api */
final class ContextExpiredException extends ContextException
{
    public function __construct(private int $deadlineNs)
    {
        parent::__construct('Context deadline exceeded');
    }

    public function deadlineNs(): int
    {
        return $this->deadlineNs;
    }
}
