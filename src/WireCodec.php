<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/**
 * Carries the remaining budget and allow-listed values across a process
 * boundary. The deadline travels as remaining milliseconds (monotonic time is
 * meaningless elsewhere); values travel only for classes implementing
 * {@see WireValue}, under stable aliases. Cancellation is not carried.
 *
 * @psalm-type WireFormat = array{budget_ms: positive-int|null, values: array<string, string>}
 *
 * @api
 */
final readonly class WireCodec
{
    private const string ALIAS_PATTERN = '/^[A-Za-z0-9_.-]{1,64}\z/';

    private const int MAX_BUDGET_MS = 999_999_999;

    /** @var array<string, class-string<WireValue>> */
    private array $aliases;

    /** @var (\Closure(string, \Throwable): void)|null */
    private ?\Closure $onInvalid;

    /**
     * @param array<string, class-string> $aliases alias => value class
     * @param (callable(string, \Throwable): void)|null $onInvalid receives the
     *        alias and the error of a dropped incoming value
     */
    public function __construct(array $aliases, ?callable $onInvalid = null)
    {
        $checked = [];
        foreach ($aliases as $alias => $class) {
            if (preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
                throw new \InvalidArgumentException(sprintf('Invalid wire alias "%s"', $alias));
            }

            if (!is_subclass_of($class, WireValue::class)) {
                throw new \InvalidArgumentException(sprintf('Wire value class "%s" must implement %s', $class, WireValue::class));
            }

            $checked[$alias] = $class;
        }

        $this->aliases = $checked;
        $this->onInvalid = $onInvalid === null ? null : \Closure::fromCallable($onInvalid);
    }

    /**
     * @return WireFormat
     *
     * @throws ContextException when the context is terminated
     */
    public function encode(Context $context): array
    {
        // Read before the check: once assertActive() passes, the budget is
        // never the 0.0 of an expired context.
        $remainingMs = $context->remainingMs();
        $context->assertActive();

        $values = [];
        foreach ($this->aliases as $alias => $class) {
            $value = $context->value($class);
            if ($value instanceof WireValue) {
                $values[$alias] = $value->toWire();
            }
        }

        return [
            'budget_ms' => $remainingMs === null ? null : max(1, (int) $remainingMs),
            'values' => $values,
        ];
    }

    /**
     * Rebuilds a context under `$parent` from untrusted input. The incoming
     * budget is capped by `$maxBudgetMs` and can only shorten the parent's;
     * a malformed budget is ignored; unknown aliases and values whose
     * `fromWire()` throws are dropped. Never throws on bad input.
     *
     * @param positive-int $maxBudgetMs
     *
     * @return array{Context, ContextController}
     */
    public function decode(mixed $wire, Context $parent, int $maxBudgetMs): array
    {
        $wire = \is_array($wire) ? $wire : [];
        $budgetMs = $this->budget($wire['budget_ms'] ?? null);

        [$context, $controller] = $budgetMs === null
            ? $parent->withCancel()
            : $parent->withTimeout((float) min($budgetMs, $maxBudgetMs) / 1000.0);

        $values = $this->map($wire['values'] ?? null);
        foreach ($this->aliases as $alias => $class) {
            $raw = $values[$alias] ?? null;
            if (!\is_string($raw)) {
                continue;
            }

            try {
                $context = $context->withValue($class, $class::fromWire($raw));
            } catch (\Throwable $error) {
                if ($this->onInvalid instanceof \Closure) {
                    ($this->onInvalid)($alias, $error);
                }
            }
        }

        return [$context, $controller];
    }

    /** @return array<array-key, mixed> */
    private function map(mixed $raw): array
    {
        return \is_array($raw) ? $raw : [];
    }

    /** @return positive-int|null */
    private function budget(mixed $raw): ?int
    {
        if (\is_string($raw) && preg_match('/^[0-9]{1,9}\z/', $raw) === 1) {
            $raw = (int) $raw;
        }

        if (!\is_int($raw) || $raw < 1 || $raw > self::MAX_BUDGET_MS) {
            return null;
        }

        return $raw;
    }
}
