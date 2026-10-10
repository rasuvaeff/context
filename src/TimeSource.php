<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/** @api */
interface TimeSource
{
    /** Returns monotonic time in nanoseconds. */
    public function nowNs(): int;
}
