<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Tests\Support\FakeTimeSource;
use Rasuvaeff\Context\Tests\Support\RequestId;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
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

    public function invalidTimeoutsAreRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        Context::background()->withTimeout(INF);
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
        $controller->cancel();

        Assert::true($childCalled);
        Assert::true($child->isCancelled());
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
}
