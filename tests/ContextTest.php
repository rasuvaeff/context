<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Tests\Support\RequestId;
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
#[Covers(Context::class)]
#[Covers(ContextCancelledException::class)]
#[Covers(ContextExpiredException::class)]
final class ContextTest
{
    public function cancellationPropagatesToDescendants(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        [$child] = $context->withCancel();
        [$grandchild] = $child->withCancel();

        $controller->cancel();

        Assert::true($context->isCancelled());
        Assert::true($child->isCancelled());
        Assert::true($grandchild->isCancelled());
    }

    public function childCancellationDoesNotAffectParentOrSibling(): void
    {
        [$context] = Context::background()->withCancel();
        [$first, $firstController] = $context->withCancel();
        [$second] = $context->withCancel();

        $firstController->cancel();

        Assert::true($first->isCancelled());
        Assert::false($context->isTerminated());
        Assert::false($second->isTerminated());
    }

    public function expiredAncestorTerminatesWholeSubtreeWhenChildIsObserved(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(1.0);
        [$child] = $context->withCancel();
        [$sibling] = Context::background($clock)->withCancel();

        $clock->advanceNs(1_000_000_001);

        Expect::exception(ContextExpiredException::class);
        $child->assertActive();

        Assert::true($context->isExpired());
        Assert::true($child->isExpired());
        Assert::false($sibling->isTerminated());
    }

    public function childDeadlineOnlyTerminatesChildSubtree(): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withCancel();
        [$child] = $parent->withTimeout(1.0);
        [$grandchild] = $child->withCancel();

        $clock->advanceNs(1_000_000_001);
        Assert::true($grandchild->isExpired());

        Assert::false($parent->isTerminated());
        Assert::true($child->isExpired());
    }

    public function activeContextPredicatesAreFalse(): void
    {
        $context = Context::background();

        Assert::false($context->isTerminated());
        Assert::false($context->isCancelled());
        Assert::false($context->isExpired());
    }

    public function observingExpiredContextUpdatesAllPredicates(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withDeadline(1_000_000_000);
        $clock->advanceNs(1_000_000_001);

        $listenerCalled = false;
        $context->onTerminate(static function () use (&$listenerCalled): void {
            $listenerCalled = true;
        });

        Assert::false($context->isCancelled());
        Assert::true($listenerCalled);
        Assert::true($context->isTerminated());
        Assert::true($context->isExpired());
    }

    public function expiredRemainingBudgetIsZero(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withDeadline(1_000_000_000);
        $clock->advanceNs(1_000_000_001);

        Assert::same($context->remainingMs(), 0.0);
    }

    public function equalDeadlineBelongsToTheParentOwner(): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withDeadline(2_000_000_000);
        [$child] = $parent->withDeadline(2_000_000_000);
        $clock->advanceNs(1_000_000_000);

        Assert::true($child->isExpired());
        Assert::true($parent->isExpired());
    }

    public function exactDeadlineIsExpired(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withDeadline(2_000_000_000);
        $clock->advanceNs(1_000_000_000);

        Assert::true($context->isExpired());
    }

    public function expiredExceptionKeepsTheDeadlineValue(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withDeadline(1_234_567_890);
        $clock->advanceNs(1_234_567_891);

        Expect::exception(ContextExpiredException::class);

        try {
            $context->assertActive();
        } catch (ContextExpiredException $exception) {
            Assert::same($exception->deadlineNs(), 1_234_567_890);
            Assert::same($exception->getMessage(), 'Context deadline exceeded');

            throw $exception;
        }
    }

    public function fractionalRemainingMillisecondsAreFloored(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withDeadline(3_000_000_000);

        $clock->advanceNs(1_000_001_000);
        Assert::same($context->remainingMs(), 999.0);

        [$other] = Context::background($clock)->withDeadline(5_000_000_000);
        $clock->advanceNs(2_997_999_001);
        Assert::same($other->remainingMs(), 1.0);
    }

    public function missingRootValueIsNull(): void
    {
        Assert::same(Context::background()->value(RequestId::class), null);
    }

    public function earlierChildDeadlineDoesNotTerminateParent(): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withDeadline(3_000_000_000);
        [$child] = $parent->withDeadline(2_000_000_000);

        $clock->advanceNs(1_000_000_001);

        Assert::true($child->isExpired());
        Assert::false($parent->isTerminated());
    }

    public function cancellationReasonIsPrevious(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $reason = new \RuntimeException('client disconnected');
        $controller->cancel($reason);

        Expect::exception(ContextCancelledException::class);

        try {
            $context->assertActive();
        } catch (ContextCancelledException $exception) {
            Assert::same($exception->getPrevious(), $reason);

            throw $exception;
        }
    }

    public function deadlineRemainingIsFlooredAndKeepsActiveSubMillisecondBudget(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(0.000_4);

        Assert::same($context->remainingMs(), 1.0);
    }

    public function valuesAreNearestAndTyped(): void
    {
        $root = Context::background()->withValue(RequestId::class, new RequestId('root'));
        $child = $root->withValue(RequestId::class, new RequestId('child'));

        Assert::same($child->value(RequestId::class)?->value, 'child');
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function invalidTimeoutsAreRejected(float $seconds): void
    {
        Expect::exception(\InvalidArgumentException::class);
        Context::background()->withTimeout($seconds);
    }

    public static function invalidTimeoutProvider(): iterable
    {
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    public function equalChildDeadlineKeepsAncestorAsOwner(): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withDeadline(2_000_000_000);
        [$child] = $parent->withDeadline(2_000_000_000);

        $clock->advanceNs(500_000_000);
        Assert::false($child->isTerminated());
        $clock->advanceNs(1_500_000_001);

        Assert::true($child->isExpired());
        Assert::true($parent->isExpired());
    }

    public function listenerFailureDoesNotStopCascade(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        [$child] = $context->withCancel();
        $childCalled = false;
        $context->onTerminate(static function (): void {
            throw new \RuntimeException('first');
        });
        $context->onTerminate(static function (): void {
            throw new \RuntimeException('second');
        });
        $child->onTerminate(static function () use (&$childCalled): void {
            $childCalled = true;
        });

        Expect::exception(\RuntimeException::class);

        try {
            $controller->cancel();
        } finally {
            Assert::true($childCalled);
            Assert::true($child->isCancelled());
        }
    }

    #[Property(runs: 200)]
    public function remainingBudgetNeverIncreases(int $advanceMs, int $initialMs): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout($initialMs / 1000.0);
        $before = $context->remainingMs();
        $clock->advanceNs($advanceMs * 1_000_000);
        $after = $context->remainingMs();

        Assert::true($before === null || $after === null || $after <= $before);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function remainingBudgetNeverIncreasesGenerators(): array
    {
        return [
            'advanceMs' => Gen::intBetween(0, 1_000),
            'initialMs' => Gen::intBetween(1, 2_000),
        ];
    }

    #[Property(runs: 200)]
    public function derivedDeadlineNeverExpandsParent(int $parentMs, int $childMs): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withTimeout($parentMs / 1000.0);
        [$child] = $parent->withTimeout($childMs / 1000.0);

        Assert::true($child->deadlineNs() <= $parent->deadlineNs());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function derivedDeadlineNeverExpandsParentGenerators(): array
    {
        return [
            'parentMs' => Gen::intBetween(1, 2_000),
            'childMs' => Gen::intBetween(1, 2_000),
        ];
    }

    /**
     * @param non-empty-list<array{int, int}> $nodes [parent pick, own deadline in ms or 0 for none]
     */
    #[Property(runs: 300)]
    public function observingANodeTerminatesExactlyTheSubtreeOwningItsDeadline(
        array $nodes,
        int $advanceMs,
        int $observePick,
    ): void {
        $clock = new FakeTimeSource();
        $startNs = $clock->nowNs();
        $contexts = [];
        $parents = [];
        $effectiveMs = [];
        $owners = [];
        $terminated = [];

        foreach ($nodes as $i => [$pick, $deadlineMs]) {
            $parent = $i === 0 ? -1 : $pick % $i;
            $base = $parent === -1 ? Context::background($clock) : $contexts[$parent];
            $inheritedMs = $effectiveMs[$parent] ?? null;
            $inheritedOwner = $owners[$parent] ?? null;

            if ($deadlineMs === 0) {
                [$context] = $base->withCancel();
                $effectiveMs[$i] = $inheritedMs;
                $owners[$i] = $inheritedOwner;
            } else {
                [$context] = $base->withDeadline($startNs + $deadlineMs * 1_000_000);
                $ownsDeadline = $inheritedMs === null || $deadlineMs < $inheritedMs;
                $effectiveMs[$i] = $ownsDeadline ? $deadlineMs : $inheritedMs;
                $owners[$i] = $ownsDeadline ? $i : $inheritedOwner;
            }

            $context->onTerminate(static function () use (&$terminated, $i): void {
                $terminated[] = $i;
            });
            $contexts[] = $context;
            $parents[] = $parent;
        }

        $observed = $observePick % \count($contexts);
        $clock->advanceNs($advanceMs * 1_000_000);
        $contexts[$observed]->isTerminated();

        $deadline = $effectiveMs[$observed];
        $owner = $owners[$observed];
        $expected = $deadline !== null && $owner !== null && $advanceMs >= $deadline
            ? $this->subtree($parents, $owner)
            : [];
        sort($terminated);

        Assert::same($terminated, $expected);

        Classify::cover($expected !== [] && $owner === $observed, 'own deadline expired', 10);
        Classify::cover($expected !== [] && $owner !== $observed, 'an ancestor deadline expired', 10);
        Classify::cover($expected === [], 'nothing expired', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function observingANodeTerminatesExactlyTheSubtreeOwningItsDeadlineGenerators(): array
    {
        return [
            'nodes' => Gen::nonEmptyArrayOf(
                Gen::tuple(
                    Gen::intBetween(0, 1_000),
                    Gen::frequency([[1, Gen::constant(value: 0)], [2, Gen::intBetween(1, 100)]]),
                ),
                10,
            ),
            'advanceMs' => Gen::intBetween(0, 120),
            'observePick' => Gen::intBetween(0, 1_000),
        ];
    }

    /** @return iterable<string, array{non-empty-list<array{int, int}>, int, int}> */
    public static function observingANodeTerminatesExactlyTheSubtreeOwningItsDeadlineExamples(): iterable
    {
        yield 'own deadline leaves the parent active' => [[[0, 0], [0, 10]], 10, 1];
        yield 'an ancestor deadline reaches a sibling' => [[[0, 10], [0, 0], [0, 0]], 20, 2];
        yield 'an equal deadline belongs to the ancestor' => [[[0, 10], [0, 10]], 10, 1];
        yield 'one millisecond before the deadline' => [[[0, 10]], 9, 0];
    }

    /**
     * @param non-empty-list<array{int, bool}> $nodes [parent pick, has a throwing listener]
     */
    #[Property(runs: 300)]
    public function aThrowingListenerNeverStopsTheCascade(array $nodes, int $cancelPick): void
    {
        [$root] = Context::background()->withCancel();
        $contexts = [];
        $controllers = [];
        $parents = [];
        $notified = [];
        $throwing = [];

        foreach ($nodes as $i => [$pick, $throws]) {
            $parent = $i === 0 ? -1 : $pick % $i;
            [$context, $controller] = ($parent === -1 ? $root : $contexts[$parent])->withCancel();

            if ($throws) {
                // Registered first, so the recording listener after it proves isolation.
                $context->onTerminate(static function () use ($i): void {
                    throw new \RuntimeException('listener ' . $i);
                });
                $throwing[] = $i;
            }
            $context->onTerminate(static function () use (&$notified, $i): void {
                $notified[] = $i;
            });
            $contexts[] = $context;
            $controllers[] = $controller;
            $parents[] = $parent;
        }

        $target = $cancelPick % \count($contexts);
        $subtree = $this->subtree($parents, $target);
        $failing = array_values(array_intersect($throwing, $subtree));
        $error = null;

        try {
            $controllers[$target]->cancel();
        } catch (\RuntimeException $caught) {
            $error = $caught;
        }
        sort($notified);

        Assert::same($notified, $subtree);
        foreach ($contexts as $i => $context) {
            Assert::same($context->isCancelled(), \in_array($i, $subtree, strict: true));
        }
        Assert::same($error instanceof \RuntimeException, $failing !== []);
        if (\count($failing) === 1) {
            Assert::same($error?->getMessage(), 'listener ' . $failing[0]);
        }

        Classify::cover(\count($failing) > 1, 'several listeners failed', 5);
        Classify::cover(\count($failing) === 1, 'one listener failed', 10);
        Classify::cover($failing === [], 'no listener failed', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aThrowingListenerNeverStopsTheCascadeGenerators(): array
    {
        return [
            'nodes' => Gen::nonEmptyArrayOf(
                Gen::tuple(
                    Gen::intBetween(0, 1_000),
                    Gen::frequency([[3, Gen::constant(value: false)], [1, Gen::constant(value: true)]]),
                ),
                10,
            ),
            'cancelPick' => Gen::intBetween(0, 1_000),
        ];
    }

    /** @return iterable<string, array{non-empty-list<array{int, bool}>, int}> */
    public static function aThrowingListenerNeverStopsTheCascadeExamples(): iterable
    {
        yield 'two failing listeners on the cancelled node' => [[[0, true], [0, true], [1, false]], 0];
        yield 'a failing child does not stop its sibling' => [[[0, false], [0, true], [0, false]], 0];
        yield 'a failure outside the subtree is not raised' => [[[0, false], [0, true], [0, false]], 2];
    }

    /**
     * @param list<int> $parents
     *
     * @return list<int>
     */
    private function subtree(array $parents, int $root): array
    {
        $nodes = [];
        foreach (array_keys($parents) as $node) {
            $ancestor = $node;
            while ($ancestor !== -1 && $ancestor !== $root) {
                $ancestor = $parents[$ancestor];
            }
            if ($ancestor === $root) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
