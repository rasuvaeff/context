<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests\Support;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Internal\ContextState;

/**
 * Reads the internal state graph to prove that terminated nodes are released.
 */
final class StateInspector
{
    public static function state(Context $context): ContextState
    {
        $state = (new \ReflectionProperty(Context::class, 'state'))->getValue($context);
        if (!$state instanceof ContextState) {
            throw new \LogicException('Context has no state');
        }

        return $state;
    }

    public static function childCount(Context $context): int
    {
        return \count(self::children(self::state($context)));
    }

    public static function parentCount(Context $context): int
    {
        $parents = (new \ReflectionProperty(ContextState::class, 'parents'))->getValue(self::state($context));

        return \is_array($parents) ? \count($parents) : 0;
    }

    /**
     * True when an active node still references a terminated child.
     *
     * @param list<Context> $contexts
     */
    public static function retainsTerminatedChild(array $contexts): bool
    {
        foreach ($contexts as $context) {
            $state = self::state($context);
            if ($state->cause() instanceof \Rasuvaeff\Context\TerminationCause) {
                continue;
            }

            foreach (self::children($state) as $child) {
                if ($child->cause() !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<ContextState> */
    private static function children(ContextState $state): array
    {
        $children = (new \ReflectionProperty(ContextState::class, 'children'))->getValue($state);
        $result = [];
        foreach (\is_array($children) ? $children : [] as $child) {
            if ($child instanceof ContextState) {
                $result[] = $child;
            }
        }

        return $result;
    }
}
