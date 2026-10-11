<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/**
 * @api
 */
final class ContextExpiredException extends ContextException
{
    public function __construct(private readonly int $deadlineNs, ?\Throwable $previous = null)
    {
        parent::__construct('Context deadline exceeded', previous: $previous);
    }

    public function deadlineNs(): int
    {
        return $this->deadlineNs;
    }
}
