<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/**
 * Thrown when more than one termination listener (or a descendant's cascade)
 * fails during one termination. `errors()` returns every failure in the order
 * it happened; a descendant whose own cascade failed contributes the exception
 * it threw. The `previous` chain ends with the last failure.
 *
 * @api
 */
final class ListenerFailuresException extends \RuntimeException
{
    /**
     * @param non-empty-list<\Throwable> $errors
     */
    public function __construct(private readonly array $errors)
    {
        $chain = $this->errors[\count($this->errors) - 1];
        for ($i = \count($this->errors) - 1; $i > 0; --$i) {
            $chain = new \RuntimeException('Context termination listener failed', previous: $chain);
        }

        parent::__construct('One or more context termination listeners failed', previous: $chain);
    }

    /**
     * @return non-empty-list<\Throwable>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
