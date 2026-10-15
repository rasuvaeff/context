<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Testing;

use Rasuvaeff\Context\Sleeper;
use Rasuvaeff\Context\TimeSource;

/**
 * Deterministic clock for tests. Sleeping advances it, so passing one
 * instance to {@see \Rasuvaeff\Context\Context::background()} drives both
 * deadlines and {@see \Rasuvaeff\Context\Context::sleep()}.
 *
 * @api
 */
final class FakeTimeSource implements TimeSource, Sleeper
{
    public function __construct(private int $nowNs = 1_000_000_000) {}

    #[\Override]
    public function nowNs(): int
    {
        return $this->nowNs;
    }

    #[\Override]
    public function sleepNs(int $nanoseconds): void
    {
        $this->advanceNs($nanoseconds);
    }

    public function advanceNs(int $nanoseconds): void
    {
        if ($nanoseconds > 0) {
            $this->nowNs += $nanoseconds;
        }
    }

    public function advanceMs(int|float $milliseconds): void
    {
        $this->advanceNs((int) round((float) $milliseconds * 1_000_000.0));
    }
}
