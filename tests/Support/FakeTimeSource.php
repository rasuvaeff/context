<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests\Support;

use Rasuvaeff\Context\TimeSource;

final class FakeTimeSource implements TimeSource
{
    /** @param int<0, max> $nowNs */
    public function __construct(private int $nowNs = 1_000_000_000) {}

    #[\Override]
    public function nowNs(): int
    {
        return $this->nowNs;
    }

    /** @param int<0, max> $nanoseconds */
    public function advanceNs(int $nanoseconds): void
    {
        $this->nowNs += $nanoseconds;
    }
}
