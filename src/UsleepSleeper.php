<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/**
 * Production {@see Sleeper} over `usleep()`; a delivered signal wakes it early.
 *
 * @api
 */
final readonly class UsleepSleeper implements Sleeper
{
    #[\Override]
    public function sleepNs(int $nanoseconds): void
    {
        usleep(max(0, intdiv($nanoseconds, 1_000)));
    }
}
