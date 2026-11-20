<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Tests\Support\Locale;
use Rasuvaeff\Context\Tests\Support\RequestId;
use Rasuvaeff\Context\Tests\Support\TenantId;
use Rasuvaeff\Context\WireCodec;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WireCodec::class)]
final class WireCodecTest
{
    public function encodesTheBudgetAndAllowListedValues(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(2.0);
        $context = $context
            ->withValue(TenantId::class, new TenantId('acme'))
            ->withValue(RequestId::class, new RequestId('not-on-the-wire'));
        $clock->advanceMs(250);

        Assert::same($this->codec()->encode($context), [
            'budget_ms' => 1_750,
            'values' => ['tenant' => 'acme'],
        ]);
    }

    public function aMissingBudgetNeverLiftsTheLimit(): void
    {
        [$context] = $this->codec()->decode(['values' => ['tenant' => 'acme']], Context::background(new FakeTimeSource()), maxBudgetMs: 2_000);

        Assert::same($context->remainingMs(), 2_000.0);
    }

    public function encodesNoBudgetWithoutADeadline(): void
    {
        Assert::same($this->codec()->encode(Context::background()), ['budget_ms' => null, 'values' => []]);
    }

    public function refusesToEncodeATerminatedContext(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();

        Expect::exception(ContextCancelledException::class);
        $this->codec()->encode($context);
    }

    public function decodesValuesAndAShorterBudget(): void
    {
        $clock = new FakeTimeSource();
        $wire = ['budget_ms' => 800, 'values' => ['tenant' => 'acme', 'locale' => 'de', 'unknown' => 'x']];

        [$context, $controller] = $this->codec()->decode($wire, Context::background($clock), maxBudgetMs: 5_000);

        Assert::same($context->remainingMs(), 800.0);
        Assert::same($context->value(TenantId::class)?->value, 'acme');
        Assert::same($context->value(Locale::class)?->value, 'de');

        $controller->cancel();

        Assert::true($context->isCancelled());
    }

    public function theIncomingBudgetNeverExceedsTheLimitOrTheParent(): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withTimeout(0.3);

        [$capped] = $this->codec()->decode(['budget_ms' => 900_000], Context::background($clock), maxBudgetMs: 2_000);
        [$byParent] = $this->codec()->decode(['budget_ms' => 900_000], $parent, maxBudgetMs: 2_000);

        Assert::same($capped->remainingMs(), 2_000.0);
        Assert::same($byParent->remainingMs(), 300.0);
    }

    public function theLargestBudgetIsAccepted(): void
    {
        [$context] = $this->codec()->decode(['budget_ms' => 999_999_999], Context::background(new FakeTimeSource()), maxBudgetMs: 1_000);

        Assert::same($context->remainingMs(), 1_000.0);
    }

    public function aMissingValueDoesNotStopTheOthers(): void
    {
        [$context] = $this->codec()->decode(['values' => ['locale' => 'de']], Context::background(), maxBudgetMs: 1_000);

        Assert::null($context->value(TenantId::class));
        Assert::same($context->value(Locale::class)?->value, 'de');
    }

    public function aDigitStringBudgetIsAccepted(): void
    {
        [$context] = $this->codec()->decode(['budget_ms' => '450'], Context::background(new FakeTimeSource()), maxBudgetMs: 5_000);

        Assert::same($context->remainingMs(), 450.0);
    }

    #[DataProvider('malformedBudgetProvider')]
    public function aMalformedBudgetFallsBackToTheLimit(mixed $budget): void
    {
        [$context] = $this->codec()->decode(['budget_ms' => $budget], Context::background(new FakeTimeSource()), maxBudgetMs: 5_000);

        Assert::same($context->remainingMs(), 5_000.0);
        Assert::false($context->isTerminated());
    }

    public static function malformedBudgetProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'too large' => [1_000_000_000];
        yield 'float' => [12.5];
        yield 'trailing newline' => ["12\n"];
        yield 'letters' => ['12a'];
        yield 'leading space' => [' 12'];
        yield 'too many digits' => ['1234567890'];
        yield 'array' => [[1]];
        yield 'null' => [null];
    }

    #[DataProvider('budgetFromWireProvider')]
    public function budgetFromWireIsTheSingleBudgetRule(mixed $raw, ?int $expected): void
    {
        Assert::same(WireCodec::budgetFromWire($raw), $expected);
    }

    public static function budgetFromWireProvider(): iterable
    {
        yield 'int' => [750, 750];
        yield 'digit string' => ['750', 750];
        yield 'leading zeros' => ['007', 7];
        yield 'smallest' => ['1', 1];
        yield 'largest' => ['999999999', 999_999_999];
        yield 'zero' => ['0', null];
        yield 'zeros only' => ['000', null];
        yield 'int zero' => [0, null];
        yield 'int too large' => [1_000_000_000, null];
        yield 'ten digits' => ['1000000000', null];
        yield 'negative' => ['-5', null];
        yield 'plus sign' => ['+5', null];
        yield 'fraction' => ['1.5', null];
        yield 'exponent' => ['1e3', null];
        yield 'trailing newline' => ["750\n", null];
        yield 'empty' => ['', null];
        yield 'float' => [12.0, null];
        yield 'null' => [null, null];
    }

    /**
     * A string budget is accepted exactly when it is 1–9 digits that are not
     * all zeros, and then it is its decimal value.
     */
    #[Property(runs: 300)]
    public function aStringBudgetIsAcceptedExactlyWhenItIsNineDigitsAtMostAndNotZero(string $raw): void
    {
        $valid = preg_match('/^[0-9]{1,9}\z/', $raw) === 1 && (int) $raw > 0;

        Assert::same(WireCodec::budgetFromWire($raw), $valid ? (int) $raw : null);

        Classify::cover($valid, 'accepted', 20);
        Classify::cover(!$valid, 'rejected', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aStringBudgetIsAcceptedExactlyWhenItIsNineDigitsAtMostAndNotZeroGenerators(): array
    {
        return [
            'raw' => Gen::frequency([
                [3, Gen::regex('[0-9]{1,9}')],
                [1, Gen::regex('[0-9]{10,12}')],
                [1, Gen::stringFrom('0123456789+-.e \n', 0, 6)],
            ]),
        ];
    }

    public function anInvalidValueIsDroppedAndReported(): void
    {
        $reported = [];
        $codec = new WireCodec(
            ['tenant' => TenantId::class, 'locale' => Locale::class],
            static function (string $alias, \Throwable $error) use (&$reported): void {
                $reported[] = [$alias, $error->getMessage()];
            },
        );

        [$context] = $codec->decode(['values' => ['tenant' => "acme\n", 'locale' => 'de']], Context::background(), maxBudgetMs: 1_000);

        Assert::null($context->value(TenantId::class));
        Assert::same($context->value(Locale::class)?->value, 'de');
        Assert::same($reported, [['tenant', "Invalid tenant id \"acme\n\""]]);
    }

    #[DataProvider('malformedWireProvider')]
    public function malformedInputDecodesToTheLimitWithoutValues(mixed $wire): void
    {
        [$context] = $this->codec()->decode($wire, Context::background(new FakeTimeSource()), maxBudgetMs: 1_000);

        Assert::same($context->remainingMs(), 1_000.0);
        Assert::null($context->value(TenantId::class));
    }

    public static function malformedWireProvider(): iterable
    {
        yield 'not an array' => ['tenant=acme'];
        yield 'values not an array' => [['values' => 'acme']];
        yield 'value not a string' => [['values' => ['tenant' => ['acme']]]];
        yield 'list instead of map' => [['values' => ['acme']]];
    }

    #[DataProvider('invalidAliasProvider')]
    public function invalidAliasesAreRejected(string $alias): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new WireCodec([$alias => TenantId::class]);
    }

    public static function invalidAliasProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['ten ant'];
        yield 'trailing newline' => ["tenant\n"];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'baggage separator' => ['tenant,x'];
    }

    public function aNumericAliasIsAcceptedAndRoundTrips(): void
    {
        $codec = new WireCodec(['1' => TenantId::class, '0' => Locale::class]);
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1.0);
        $context = $context
            ->withValue(TenantId::class, new TenantId('acme'))
            ->withValue(Locale::class, new Locale('en'));

        $wire = $codec->encode($context);

        Assert::same($wire['values'], [1 => 'acme', 0 => 'en']);

        [$decoded] = $codec->decode($wire, Context::background(new FakeTimeSource()), 1_000);

        Assert::same($decoded->value(TenantId::class)?->value, 'acme');
        Assert::same($decoded->value(Locale::class)?->value, 'en');
    }

    public function aNumericAliasSurvivesAJsonRoundTrip(): void
    {
        $codec = new WireCodec(['0' => TenantId::class]);
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1.0);
        $wire = $codec->encode($context->withValue(TenantId::class, new TenantId('acme')));

        $json = json_decode(json_encode($wire, \JSON_THROW_ON_ERROR), associative: true, flags: \JSON_THROW_ON_ERROR);
        [$decoded] = $codec->decode($json, Context::background(new FakeTimeSource()), 1_000);

        Assert::same($decoded->value(TenantId::class)?->value, 'acme');
    }

    public function aClassThatIsNotAWireValueIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new WireCodec(['request' => RequestId::class]);
    }

    #[Property(runs: 300)]
    public function aRoundTripKeepsValuesAndNeverExtendsTheBudget(string $tenant, ?string $locale, ?int $budgetMs): void
    {
        $clock = new FakeTimeSource();
        $source = Context::background($clock);
        if ($budgetMs !== null) {
            [$source] = $source->withTimeout($budgetMs / 1000.0);
        }
        $source = $source->withValue(TenantId::class, new TenantId($tenant));
        if ($locale !== null) {
            $source = $source->withValue(Locale::class, new Locale($locale));
        }

        $wire = json_decode(json_encode($this->codec()->encode($source), \JSON_THROW_ON_ERROR), associative: true, flags: \JSON_THROW_ON_ERROR);
        [$decoded] = $this->codec()->decode($wire, Context::background($clock), maxBudgetMs: 60_000);

        Assert::same($decoded->value(TenantId::class)?->value, $tenant);
        Assert::same($decoded->value(Locale::class)?->value, $locale);
        Assert::same($decoded->remainingMs(), $source->remainingMs() ?? 60_000.0);

        Classify::cover($budgetMs !== null, 'with a budget', 20);
        Classify::cover($locale === null, 'without a locale', 10);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aRoundTripKeepsValuesAndNeverExtendsTheBudgetGenerators(): array
    {
        return [
            'tenant' => Gen::regex('[a-z0-9-]{1,32}'),
            'locale' => Gen::nullable(Gen::regex('[a-z]{2}')),
            'budgetMs' => Gen::nullable(Gen::intBetween(1, 60_000)),
        ];
    }

    #[Property(runs: 300)]
    public function decodingArbitraryInputNeverThrows(mixed $wire): void
    {
        [$context] = $this->codec()->decode($wire, Context::background(), maxBudgetMs: 1_000);

        Assert::false($context->isTerminated());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function decodingArbitraryInputNeverThrowsGenerators(): array
    {
        $scalar = Gen::frequency([
            [2, Gen::stringAscii()],
            [1, Gen::int()],
            [1, Gen::constant(value: null)],
        ]);

        return [
            'wire' => Gen::frequency([
                [1, Gen::json()],
                [3, Gen::record([
                    'budget_ms' => $scalar,
                    'values' => Gen::dictOf(Gen::elements(['tenant', 'locale', 'other']), $scalar),
                ])],
            ]),
        ];
    }

    public function encodeValuesCarriesOnlyTheAllowListedValues(): void
    {
        $context = Context::background()
            ->withValue(TenantId::class, new TenantId('acme'))
            ->withValue(RequestId::class, new RequestId('not-on-the-wire'));

        Assert::same($this->codec()->encodeValues($context), ['tenant' => 'acme']);
    }

    public function encodeValuesReadsATerminatedContext(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $context = $context->withValue(TenantId::class, new TenantId('acme'));
        $controller->cancel();

        Assert::same($this->codec()->encodeValues($context), ['tenant' => 'acme']);
    }

    public function decodeValuesKeepsTheDeadlineAndTheCancellationOfTheContext(): void
    {
        $clock = new FakeTimeSource();
        [$parent, $controller] = Context::background($clock)->withTimeout(2.0);

        $context = $this->codec()->decodeValues(['tenant' => 'acme', 'locale' => 'de', 'other' => 'x'], $parent);

        Assert::same($context->value(TenantId::class)?->value, 'acme');
        Assert::same($context->value(Locale::class)?->value, 'de');
        Assert::same($context->deadlineNs(), $parent->deadlineNs());

        $controller->cancel();

        Assert::true($context->isCancelled());
    }

    public function decodeValuesDropsAnInvalidValueAndReportsIt(): void
    {
        $reported = [];
        $codec = new WireCodec(
            ['tenant' => TenantId::class, 'locale' => Locale::class],
            static function (string $alias) use (&$reported): void {
                $reported[] = $alias;
            },
        );

        $context = $codec->decodeValues(['tenant' => 'NOT VALID', 'locale' => 'de'], Context::background());

        Assert::null($context->value(TenantId::class));
        Assert::same($context->value(Locale::class)?->value, 'de');
        Assert::same($reported, ['tenant']);
    }

    #[DataProvider('malformedValuesProvider')]
    public function decodeValuesReturnsTheContextUnchangedOnMalformedInput(mixed $values): void
    {
        $parent = Context::background();

        Assert::same($this->codec()->decodeValues($values, $parent), $parent);
    }

    public static function malformedValuesProvider(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['tenant=acme'];
        yield 'non-string entries' => [['tenant' => 42, 'locale' => ['de']]];
        yield 'unknown aliases' => [['other' => 'x']];
    }

    #[Property(runs: 200)]
    public function valuesRoundTripWithoutTheBudget(string $tenant, ?string $locale): void
    {
        $source = Context::background()->withValue(TenantId::class, new TenantId($tenant));
        if ($locale !== null) {
            $source = $source->withValue(Locale::class, new Locale($locale));
        }

        $decoded = $this->codec()->decodeValues($this->codec()->encodeValues($source), Context::background());

        Assert::same($decoded->value(TenantId::class)?->value, $tenant);
        Assert::same($decoded->value(Locale::class)?->value, $locale);
        Assert::null($decoded->deadlineNs());

        Classify::cover($locale === null, 'without a locale', 10);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function valuesRoundTripWithoutTheBudgetGenerators(): array
    {
        return [
            'tenant' => Gen::regex('[a-z0-9-]{1,32}'),
            'locale' => Gen::nullable(Gen::regex('[a-z]{2}')),
        ];
    }

    private function codec(): WireCodec
    {
        return new WireCodec(['tenant' => TenantId::class, 'locale' => Locale::class]);
    }
}
