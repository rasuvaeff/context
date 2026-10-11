<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Internal\ContextState;
use Rasuvaeff\Context\TerminationCause;
use Rasuvaeff\Context\Tests\Support\FakeTimeSource;
use Rasuvaeff\Context\Tests\Support\RequestId;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Context::class)]
#[Covers(ContextController::class)]
#[Covers(ContextState::class)]
#[Covers(TerminationCause::class)]
#[Covers(ContextExpiredException::class)]
final class ContextLifecycleTest
{
    public function cancelledChildrenAreDetachedFromTheParent(): void
    {
        [$parent] = Context::background()->withCancel();

        for ($i = 0; $i < 100; $i++) {
            [, $controller] = $parent->withCancel();
            $controller->cancel();
        }
        [$kept] = $parent->withCancel();

        Assert::same($this->childCount($parent), 1);
        Assert::false($kept->isTerminated());
    }

    public function anExpiredChildIsDetachedOnceObserved(): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withCancel();
        [$child] = $parent->withTimeout(0.01);

        $clock->advanceNs(20_000_000);

        Assert::true($child->isExpired());
        Assert::same($this->childCount($parent), 0);
    }

    public function aChildOfATerminatedParentIsNotRetained(): void
    {
        [$parent, $controller] = Context::background()->withCancel();
        $controller->cancel();

        [$child] = $parent->withCancel();

        Assert::true($child->isCancelled());
        Assert::same($this->childCount($parent), 0);
    }

    public function valueDerivationsShareTheParentState(): void
    {
        [$parent, $controller] = Context::background()->withCancel();
        $context = $parent;

        for ($i = 0; $i < 50; $i++) {
            $context = $context->withValue(RequestId::class, new RequestId((string) $i));
        }

        Assert::same($this->childCount($parent), 0);
        Assert::same($context->value(RequestId::class)?->value, '49');

        $controller->cancel();

        Assert::true($context->isCancelled());
    }

    public function anUnsubscribedListenerNeverRuns(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $calls = [];
        $unsubscribe = $context->onTerminate(static function () use (&$calls): void {
            $calls[] = 'removed';
        });
        $context->onTerminate(static function () use (&$calls): void {
            $calls[] = 'kept';
        });

        $unsubscribe();
        $unsubscribe();
        $controller->cancel();

        Assert::same($calls, ['kept']);
    }

    public function listenersAreReleasedOnTermination(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $context->onTerminate(static function (): void {});

        Assert::same($this->listenerCount($context), 1);

        $controller->cancel();

        Assert::same($this->listenerCount($context), 0);
    }

    public function aListenerOnATerminatedContextRunsAtOnce(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();
        $causes = [];

        $unsubscribe = $context->onTerminate(static function (TerminationCause $cause) use (&$causes): void {
            $causes[] = $cause->isCancelled();
        });

        Assert::same($causes, [true]);
        Assert::same($this->listenerCount($context), 0);

        $unsubscribe();

        Assert::same($causes, [true]);
    }

    public function anOversizedTimeoutSaturatesInsteadOfOverflowing(): void
    {
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1e12);

        Assert::same($context->deadlineNs(), \PHP_INT_MAX);
        Assert::false($context->isExpired());
        Assert::true($context->remainingMs() > 1e9);
    }

    public function aTimeoutNearTheClockCeilingSaturates(): void
    {
        [$context] = Context::background(new FakeTimeSource(\PHP_INT_MAX - 1_000))->withTimeout(1.0);

        Assert::same($context->deadlineNs(), \PHP_INT_MAX);
    }

    public function anOrdinaryTimeoutIsExact(): void
    {
        [$context] = Context::background(new FakeTimeSource(1_000_000_000))->withTimeout(1.5);

        Assert::same($context->deadlineNs(), 2_500_000_000);
    }

    #[DataProvider('nonPositiveTimeoutProvider')]
    public function aNonPositiveTimeoutExpiresImmediately(float $seconds): void
    {
        [$context] = Context::background(new FakeTimeSource(1_000_000_000))->withTimeout($seconds);

        Assert::same($context->deadlineNs(), 1_000_000_000);
        Assert::true($context->isExpired());
    }

    public static function nonPositiveTimeoutProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'hugely negative' => [-1e20];
    }

    public function anExpiredExceptionCanCarryItsCause(): void
    {
        $cause = new \RuntimeException('transport timeout');
        $exception = new ContextExpiredException(123, $cause);

        Assert::same($exception->getPrevious(), $cause);
        Assert::same($exception->deadlineNs(), 123);
    }

    public function theRemainingBudgetCrossesAProcessBoundary(): void
    {
        $senderClock = new FakeTimeSource(5_000_000_000);
        [$sender] = Context::background($senderClock)->withTimeout(2.0);
        $senderClock->advanceNs(500_000_000);

        // The wire carries milliseconds; the receiver's monotonic clock is
        // unrelated to the sender's, so only the remaining budget travels.
        $header = (string) (int) $sender->remainingMs();

        $receiverClock = new FakeTimeSource(70_000_000_000);
        [$receiver] = Context::background($receiverClock)->withTimeout((int) $header / 1000.0);
        [$capped] = $receiver->withTimeout(60.0);

        Assert::same($header, '1500');
        Assert::same($receiver->remainingMs(), 1500.0);
        Assert::same($capped->remainingMs(), 1500.0);
    }

    /**
     * @param list<int> $parentPicks
     */
    #[Property(runs: 200)]
    public function cancellingANodeTerminatesExactlyItsSubtree(array $parentPicks, int $cancelPick): void
    {
        [$root] = Context::background()->withCancel();
        $contexts = [$root];
        $controllers = [null];
        $parents = [-1];

        foreach ($parentPicks as $i => $pick) {
            $parent = $pick % ($i + 1);
            [$context, $controller] = $contexts[$parent]->withCancel();
            $contexts[] = $context;
            $controllers[] = $controller;
            $parents[] = $parent;
        }

        $target = 1 + $cancelPick % \count($parentPicks);
        $controllers[$target]?->cancel();

        foreach ($contexts as $node => $context) {
            $ancestor = $node;
            while ($ancestor !== -1 && $ancestor !== $target) {
                $ancestor = $parents[$ancestor];
            }

            Assert::same($context->isCancelled(), $ancestor === $target);
        }

        Classify::cover(\in_array($target, $parents, strict: true), 'cancelled an inner node', 20);
        Classify::when(!\in_array($target, $parents, strict: true), 'cancelled a leaf');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function cancellingANodeTerminatesExactlyItsSubtreeGenerators(): array
    {
        return [
            'parentPicks' => Gen::nonEmptyArrayOf(Gen::intBetween(0, 1_000), 12),
            'cancelPick' => Gen::intBetween(0, 1_000),
        ];
    }

    /** @return iterable<string, array{list<int>, int}> */
    public static function cancellingANodeTerminatesExactlyItsSubtreeExamples(): iterable
    {
        yield 'a single child' => [[0], 0];
        yield 'the middle of a chain' => [[0, 1, 2, 3], 1];
        yield 'one of two siblings' => [[0, 0], 1];
    }

    /**
     * @param list<bool> $setAtLevel
     */
    #[Property(runs: 200)]
    public function aValueLookupFindsTheNearestAncestor(array $setAtLevel): void
    {
        $context = Context::background();
        $expected = null;

        foreach ($setAtLevel as $level => $set) {
            [$context] = $context->withCancel();
            if ($set) {
                $context = $context->withValue(RequestId::class, new RequestId((string) $level));
                $expected = (string) $level;
            }
        }

        Assert::same($context->value(RequestId::class)?->value, $expected);

        Classify::cover($expected !== null, 'found a value', 20);
        Classify::cover($expected === null, 'no value set', 5);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aValueLookupFindsTheNearestAncestorGenerators(): array
    {
        return [
            'setAtLevel' => Gen::arrayOf(Gen::frequency([[4, Gen::constant(value: false)], [1, Gen::constant(value: true)]]), 0, 8),
        ];
    }

    /** @return iterable<string, array{list<bool>}> */
    public static function aValueLookupFindsTheNearestAncestorExamples(): iterable
    {
        yield 'no levels' => [[]];
        yield 'shadowed by a nearer value' => [[true, false, true, false]];
        yield 'only the root level' => [[true, false, false]];
    }

    private function childCount(Context $context): int
    {
        return \count($this->stateProperty($context, 'children'));
    }

    private function listenerCount(Context $context): int
    {
        return \count($this->stateProperty($context, 'listeners'));
    }

    /**
     * @return array<int, mixed>
     */
    private function stateProperty(Context $context, string $name): array
    {
        $state = (new \ReflectionProperty(Context::class, 'state'))->getValue($context);
        /** @var array<int, mixed> $value */
        $value = (new \ReflectionProperty(ContextState::class, $name))->getValue($state);

        return $value;
    }
}
