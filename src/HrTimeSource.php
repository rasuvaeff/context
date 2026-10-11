<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/**
 * @api
 */
final readonly class HrTimeSource implements TimeSource
{
    #[\Override]
    public function nowNs(): int
    {
        return (int) hrtime(as_number: true);
    }
}
