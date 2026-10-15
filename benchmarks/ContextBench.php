<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Benchmarks;

use Rasuvaeff\Context\Context;
use Testo\Bench;

/**
 * A check walks the ancestors for requested cancellations: its cost grows
 * with depth, which these cases keep visible.
 */
final class ContextBench
{
    private static ?Context $shallow = null;

    private static ?Context $deep = null;

    private static ?Context $joined = null;

    #[Bench(
        callables: [
            'depth 10' => [self::class, 'checkDeep'],
            'joined at depth 10' => [self::class, 'checkJoined'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function checkShallow(): bool
    {
        return self::shallow()->isTerminated();
    }

    public static function checkDeep(): bool
    {
        return self::deep()->isTerminated();
    }

    public static function checkJoined(): bool
    {
        return self::joined()->isTerminated();
    }

    private static function shallow(): Context
    {
        return self::$shallow ??= Context::background()->withTimeout(3_600.0)[0];
    }

    private static function deep(): Context
    {
        if (self::$deep === null) {
            $context = Context::background()->withTimeout(3_600.0)[0];
            for ($i = 0; $i < 9; $i++) {
                [$context] = $context->withCancel();
            }
            self::$deep = $context;
        }

        return self::$deep;
    }

    private static function joined(): Context
    {
        return self::$joined ??= self::deep()->joinedWith(self::deep())[0];
    }
}
