<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/**
 * Blocks for a duration; the seam behind {@see Context::sleep()}. It may
 * return early (a signal, a coarse timer): callers re-check the clock.
 *
 * @api
 */
interface Sleeper
{
    public function sleepNs(int $nanoseconds): void;
}
