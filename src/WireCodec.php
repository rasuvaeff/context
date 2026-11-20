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

    /** @var list<array{string, class-string<WireValue>}> alias, value class */
    private array $aliases;

    /** @var (\Closure(string, \Throwable): void)|null */
    private ?\Closure $onInvalid;

    /**
     * @param array<array-key, class-string> $aliases alias => value class; a
     *        numeric alias (`'1'`) arrives as an int key and is accepted. It
     *        stays an int key in `encode()`'s `values`, so `json_encode()`
     *        turns aliases `0..n` into a JSON list; `decode()` still reads it,
     *        but prefer non-numeric aliases when non-PHP code reads the wire
     * @param (callable(string, \Throwable): void)|null $onInvalid receives the
     *        alias and the error of a dropped incoming value
     */
    public function __construct(array $aliases, ?callable $onInvalid = null)
    {
        $checked = [];
        foreach ($aliases as $key => $class) {
            $alias = (string) $key;
            if (preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
                throw new \InvalidArgumentException(sprintf('Invalid wire alias "%s"', $alias));
            }

            if (!is_subclass_of($class, WireValue::class)) {
                throw new \InvalidArgumentException(sprintf('Wire value class "%s" must implement %s', $class, WireValue::class));
            }

            $checked[] = [$alias, $class];
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
        return [
            'budget_ms' => $context->budgetMs(),
            'values' => $this->encodeValues($context),
        ];
    }

    /**
     * Only the allow-listed values, alias => wire string: the `values` part
     * of {@see encode()} without its budget, for a transport that carries
     * the deadline on its own. Does not check the context.
     *
     * @return array<string, string>
     */
    public function encodeValues(Context $context): array
    {
        $values = [];
        foreach ($this->aliases as [$alias, $class]) {
            $value = $context->value($class);
            if ($value instanceof WireValue) {
                $values[$alias] = $value->toWire();
            }
        }

        return $values;
    }

    /**
     * Rebuilds a context under `$parent` from untrusted input. The incoming
     * budget is capped by `$maxBudgetMs` and can only shorten the parent's;
     * a missing or malformed budget falls back to `$maxBudgetMs` — bad input
     * never lifts the limit. Unknown aliases and values whose `fromWire()`
     * throws are dropped. Never throws on bad input.
     *
     * @param positive-int $maxBudgetMs
     *
     * @return array{Context, ContextController}
     */
    public function decode(mixed $wire, Context $parent, int $maxBudgetMs): array
    {
        $wire = \is_array($wire) ? $wire : [];
        $budgetMs = self::budgetFromWire($wire['budget_ms'] ?? null);

        [$context, $controller] = $parent->withTimeout((float) min($budgetMs ?? $maxBudgetMs, $maxBudgetMs) / 1000.0);

        return [$this->decodeValues($wire['values'] ?? null, $context), $controller];
    }

    /**
     * Adds the allow-listed values from an untrusted alias => wire string
     * map (the `values` part of {@see decode()}) to `$context`, which keeps
     * its deadline and cancellation: no new controller, no budget. Unknown
     * aliases, non-string entries and values whose `fromWire()` throws are
     * dropped. Never throws on bad input.
     */
    public function decodeValues(mixed $values, Context $context): Context
    {
        $values = \is_array($values) ? $values : [];
        foreach ($this->aliases as [$alias, $class]) {
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

        return $context;
    }

    /**
     * The single rule for an incoming budget in whole milliseconds: an int
     * or a string of 1–9 digits (leading zeros allowed), value 1–999999999.
     * Anything else — zero, a sign, a fraction, an exponent, a trailing
     * newline, a longer number — is `null`, never an error.
     *
     * @return positive-int|null
     */
    public static function budgetFromWire(mixed $raw): ?int
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
