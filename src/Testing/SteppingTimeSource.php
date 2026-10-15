<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Testing;

use Rasuvaeff\Context\TimeSource;

/**
 * Moves forward by a fixed step on every read (0 keeps it still), so a
 * deadline can pass between two consecutive checks inside one call — the
 * way to test that an adapter reads the budget before `assertActive()`.
 *
 * @api
 */
final class SteppingTimeSource implements TimeSource
{
    public function __construct(
        private readonly int $stepNs,
        private int $nowNs = 1_000_000_000,
    ) {}

    #[\Override]
    public function nowNs(): int
    {
        $now = $this->nowNs;
        $this->nowNs += $this->stepNs;

        return $now;
    }
}
