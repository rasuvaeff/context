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
    public function aMalformedBudgetIsIgnored(mixed $budget): void
    {
        [$context] = $this->codec()->decode(['budget_ms' => $budget], Context::background(), maxBudgetMs: 5_000);

        Assert::null($context->deadlineNs());
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
    public function malformedInputDecodesToAPlainChild(mixed $wire): void
    {
        [$context] = $this->codec()->decode($wire, Context::background(), maxBudgetMs: 1_000);

        Assert::null($context->deadlineNs());
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
        Assert::same($decoded->remainingMs(), $source->remainingMs());

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

    private function codec(): WireCodec
    {
        return new WireCodec(['tenant' => TenantId::class, 'locale' => Locale::class]);
    }
}
