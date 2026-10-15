<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\Internal\ContextState;
use Rasuvaeff\Context\TerminationCause;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Tests\Support\RequestId;
use Rasuvaeff\Context\Tests\Support\StateInspector;
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
#[Covers(ContextController::class)]
#[Covers(ContextState::class)]
final class ContextDerivationTest
{
    public function aReserveMovesTheParentDeadlineEarlier(): void
    {
        $clock = new FakeTimeSource();
        [$request] = Context::background($clock)->withTimeout(3.0);
        [$work] = $request->withReserve(0.5);

        Assert::same($work->deadlineNs(), 3_500_000_000);
        Assert::same($work->remainingMs(), 2_500.0);
    }

    public function aReserveWithoutAParentDeadlineIsACancellableBranch(): void
    {
        [$work, $controller] = Context::background()->withReserve(0.5);

        Assert::null($work->deadlineNs());

        $controller->cancel();

        Assert::true($work->isCancelled());
    }

    public function aReserveCoveringTheBudgetIsAlreadyExpired(): void
    {
        $clock = new FakeTimeSource();
        [$request] = Context::background($clock)->withTimeout(1.0);
        [$work] = $request->withReserve(1.0);
        [$huge] = $request->withReserve(1e12);

        Assert::true($work->isExpired());
        Assert::true($huge->isExpired());
        Assert::same($huge->deadlineNs(), 0);
        Assert::false($request->isTerminated());
    }

    #[DataProvider('invalidReserveProvider')]
    public function invalidReservesAreRejected(float $seconds): void
    {
        Expect::exception(\InvalidArgumentException::class);
        Context::background()->withReserve($seconds);
    }

    public static function invalidReserveProvider(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'not a number' => [NAN];
        yield 'infinite' => [INF];
    }

    #[Property(runs: 300)]
    public function aReserveIsExactAndNeverLaterThanTheParent(int $parentMs, int $reserveMs): void
    {
        $clock = new FakeTimeSource();
        [$parent] = Context::background($clock)->withTimeout($parentMs / 1000.0);
        [$child] = $parent->withReserve($reserveMs / 1000.0);
        $parentDeadline = $parent->deadlineNs() ?? 0;
        $reserveNs = $reserveMs * 1_000_000;
        $expected = $reserveNs >= $parentDeadline ? 0 : $parentDeadline - $reserveNs;

        Assert::same($child->deadlineNs(), $expected);
        Assert::true($expected <= $parentDeadline);
        Assert::same($child->isExpired(), $reserveMs >= $parentMs);
        Assert::false($parent->isTerminated());

        Classify::cover($reserveMs >= $parentMs, 'reserve covers the budget', 10);
        Classify::cover($reserveMs < $parentMs, 'budget left', 10);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aReserveIsExactAndNeverLaterThanTheParentGenerators(): array
    {
        return [
            'parentMs' => Gen::intBetween(1, 5_000),
            'reserveMs' => Gen::intBetween(0, 6_000),
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function aReserveIsExactAndNeverLaterThanTheParentExamples(): iterable
    {
        yield 'zero reserve keeps the parent deadline' => [1_000, 0];
        yield 'reserve equal to the budget' => [1_000, 1_000];
        yield 'reserve beyond the clock origin' => [1, 6_000];
    }

    public function aDetachedContextKeepsValuesButNotTheDeadline(): void
    {
        $clock = new FakeTimeSource();
        [$request, $controller] = Context::background($clock)->withTimeout(1.0);
        $request = $request->withValue(RequestId::class, new RequestId('r-1'));
        $cleanup = $request->withoutCancel();

        $controller->cancel();
        $clock->advanceMs(5_000);

        Assert::true($request->isCancelled());
        Assert::false($cleanup->isTerminated());
        Assert::null($cleanup->deadlineNs());
        Assert::null($cleanup->remainingMs());
        Assert::same($cleanup->value(RequestId::class)?->value, 'r-1');
    }

    public function aDetachedContextIsNotRetainedByItsParent(): void
    {
        [$request] = Context::background()->withCancel();

        for ($i = 0; $i < 10; $i++) {
            $request->withoutCancel();
        }

        Assert::same(StateInspector::childCount($request), 0);
    }

    public function aDetachedContextCanBeBoundedAgain(): void
    {
        $clock = new FakeTimeSource();
        [$request, $controller] = Context::background($clock)->withTimeout(0.1);
        $controller->cancel();
        [$cleanup] = $request->withoutCancel()->withTimeout(2.0);

        $clock->advanceMs(1_000);
        Assert::false($cleanup->isTerminated());

        $clock->advanceMs(1_000);
        Assert::true($cleanup->isExpired());
    }

    /**
     * @param non-empty-list<int> $parentPicks
     */
    #[Property(runs: 200)]
    public function aDetachedContextSurvivesAnyTerminationAbove(array $parentPicks, int $detachPick, int $cancelPick): void
    {
        $clock = new FakeTimeSource();
        [$root, $rootController] = Context::background($clock)->withTimeout(1.0);
        $contexts = [$root->withValue(RequestId::class, new RequestId('root'))];
        $controllers = [$rootController];

        foreach ($parentPicks as $i => $pick) {
            [$context, $controller] = $contexts[$pick % ($i + 1)]->withCancel();
            $contexts[] = $context->withValue(RequestId::class, new RequestId('n' . $i));
            $controllers[] = $controller;
        }

        $source = $contexts[$detachPick % \count($contexts)];
        $detached = $source->withoutCancel();
        $expired = $cancelPick % 2 === 0;

        if ($expired) {
            $clock->advanceMs(2_000);
        } else {
            $controllers[$cancelPick % \count($controllers)]->cancel();
        }
        foreach ($contexts as $context) {
            $context->isTerminated();
        }

        Assert::false($detached->isTerminated());
        Assert::same($detached->value(RequestId::class), $source->value(RequestId::class));

        Classify::cover($expired, 'the root expired', 20);
        Classify::cover(!$expired, 'a node was cancelled', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aDetachedContextSurvivesAnyTerminationAboveGenerators(): array
    {
        return [
            'parentPicks' => Gen::nonEmptyArrayOf(Gen::intBetween(0, 1_000), 8),
            'detachPick' => Gen::intBetween(0, 1_000),
            'cancelPick' => Gen::intBetween(0, 1_000),
        ];
    }

    public function aJoinedContextTerminatesWithEitherParent(): void
    {
        [$worker, $workerController] = Context::background()->withCancel();
        [$message, $messageController] = Context::background()->withCancel();
        [$first] = $message->joinedWith($worker);
        [$second] = $message->joinedWith($worker);
        $reason = new \RuntimeException('shutdown');

        $workerController->cancel($reason);

        Assert::true($first->isCancelled());
        Assert::true($second->isCancelled());
        Assert::false($message->isTerminated());

        [$third] = $message->joinedWith(Context::background()->withCancel()[0]);
        $messageController->cancel();

        Assert::true($third->isCancelled());
    }

    public function theFirstCauseOfAJoinedContextWins(): void
    {
        [$worker, $workerController] = Context::background()->withCancel();
        [$message, $messageController] = Context::background()->withCancel();
        [$joined] = $message->joinedWith($worker);
        $first = new \RuntimeException('first');
        $listenerReasons = [];
        $joined->onTerminate(static function ($cause) use (&$listenerReasons): void {
            $listenerReasons[] = $cause->reason();
        });

        $workerController->cancel($first);
        $messageController->cancel(new \RuntimeException('second'));

        Assert::same($listenerReasons, [$first]);
    }

    public function aJoinedContextTakesTheEarlierDeadline(): void
    {
        $clock = new FakeTimeSource();
        [$worker] = Context::background($clock)->withTimeout(10.0);
        [$message] = Context::background($clock)->withTimeout(2.0);
        [$sibling] = $message->withCancel();
        [$joined] = $worker->joinedWith($message);

        Assert::same($joined->deadlineNs(), $message->deadlineNs());

        $clock->advanceMs(2_000);

        // The deadline is owned by $message: observing the joined node
        // terminates the owner and its whole subtree, not the worker.
        Assert::true($joined->isExpired());
        Assert::true($message->isExpired());
        Assert::true($sibling->isExpired());
        Assert::false($worker->isTerminated());
    }

    /**
     * @param 'primary'|'other'|null $owner
     */
    #[DataProvider('joinedDeadlineProvider')]
    public function aJoinedDeadlineBelongsToTheEarlierParent(?float $primaryTimeout, ?float $otherTimeout, ?int $expectedNs, ?string $owner): void
    {
        $clock = new FakeTimeSource();
        $primary = Context::background($clock);
        $other = Context::background($clock);
        if ($primaryTimeout !== null) {
            [$primary] = $primary->withTimeout($primaryTimeout);
        }
        if ($otherTimeout !== null) {
            [$other] = $other->withTimeout($otherTimeout);
        }

        [$joined] = $primary->joinedWith($other);
        $clock->advanceMs(10_000);
        $joined->isTerminated();

        Assert::same($joined->deadlineNs(), $expectedNs);
        // Observing the joined node terminates the owner of its deadline only.
        Assert::same(StateInspector::state($primary)->cause() instanceof TerminationCause, $owner === 'primary');
        Assert::same(StateInspector::state($other)->cause() instanceof TerminationCause, $owner === 'other');
    }

    public static function joinedDeadlineProvider(): iterable
    {
        yield 'primary earlier' => [1.0, 2.0, 2_000_000_000, 'primary'];
        yield 'other earlier' => [2.0, 1.0, 2_000_000_000, 'other'];
        yield 'equal stays with the primary' => [1.0, 1.0, 2_000_000_000, 'primary'];
        yield 'only the primary has one' => [1.0, null, 2_000_000_000, 'primary'];
        yield 'only the other has one' => [null, 1.0, 2_000_000_000, 'other'];
        yield 'neither has one' => [null, null, null, null];
    }

    public function joiningTheDefaultClockWithAnotherIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        Context::background()->joinedWith(Context::background(new FakeTimeSource()));
    }

    public function aJoinedContextTakesValuesFromThePrimaryOnly(): void
    {
        $primary = Context::background()->withValue(RequestId::class, new RequestId('primary'));
        $other = Context::background()->withValue(RequestId::class, new RequestId('other'));
        $bare = Context::background();

        Assert::same($primary->joinedWith($other)[0]->value(RequestId::class)?->value, 'primary');
        Assert::null($bare->joinedWith($other)[0]->value(RequestId::class));
    }

    public function joiningATerminatedContextIsTerminatedAtOnce(): void
    {
        [$worker, $workerController] = Context::background()->withCancel();
        [$message] = Context::background()->withCancel();
        $workerController->cancel();

        [$joined] = $message->joinedWith($worker);
        [$reversed] = $worker->joinedWith($message);

        Assert::true($joined->isCancelled());
        Assert::true($reversed->isCancelled());
        Assert::same(StateInspector::childCount($message), 0);
    }

    public function aTerminatedJoinedContextDetachesFromBothParents(): void
    {
        [$worker] = Context::background()->withCancel();
        [$message] = Context::background()->withCancel();

        for ($i = 0; $i < 20; $i++) {
            [, $controller] = $message->joinedWith($worker);
            $controller->cancel();
        }
        [$kept] = $message->joinedWith($worker);

        Assert::same(StateInspector::childCount($worker), 1);
        Assert::same(StateInspector::childCount($message), 1);
        Assert::same(StateInspector::parentCount($kept), 2);
    }

    public function joiningContextsOnDifferentClocksIsRejected(): void
    {
        $first = Context::background(new FakeTimeSource());
        $second = Context::background(new FakeTimeSource());

        Expect::exception(\InvalidArgumentException::class);
        $first->joinedWith($second);
    }

    public function contextsOnTheDefaultClockCanBeJoined(): void
    {
        [$joined, $controller] = Context::background()->joinedWith(Context::background());

        $controller->cancel();

        Assert::true($joined->isCancelled());
    }

    /**
     * @param non-empty-list<array{int, int, bool}> $nodes [first parent pick, second parent pick, is a join]
     */
    #[Property(runs: 300)]
    public function aJoinedNodeTerminatesExactlyWhenAParentDoes(array $nodes, int $cancelPick): void
    {
        [$left] = Context::background()->withCancel();
        [$right] = Context::background()->withCancel();
        $contexts = [$left, $right];
        $controllers = [null, null];
        $parents = [[], []];

        foreach ($nodes as [$firstPick, $secondPick, $isJoin]) {
            $count = \count($contexts);
            $first = $firstPick % $count;
            $second = $secondPick % $count;

            if ($isJoin && $first !== $second) {
                [$context, $controller] = $contexts[$first]->joinedWith($contexts[$second]);
                $parents[] = [$first, $second];
            } else {
                [$context, $controller] = $contexts[$first]->withCancel();
                $parents[] = [$first];
            }
            $contexts[] = $context;
            $controllers[] = $controller;
        }

        $target = 2 + $cancelPick % (\count($contexts) - 2);
        $controllers[$target]?->cancel();

        $terminated = [];
        foreach ($parents as $node => $nodeParents) {
            $terminated[$node] = $node === $target;
            foreach ($nodeParents as $parent) {
                $terminated[$node] = $terminated[$node] || $terminated[$parent];
            }
        }

        $joins = 0;
        $terminatedJoins = 0;
        foreach ($contexts as $node => $context) {
            Assert::same($context->isCancelled(), $terminated[$node]);
            if (\count($parents[$node]) === 2) {
                $joins++;
                $terminatedJoins += $terminated[$node] ? 1 : 0;
            }
        }
        Assert::false(StateInspector::retainsTerminatedChild($contexts));

        Classify::cover($terminatedJoins > 0, 'a join node terminated', 10);
        Classify::cover($joins > $terminatedJoins, 'a join node survived', 10);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aJoinedNodeTerminatesExactlyWhenAParentDoesGenerators(): array
    {
        return [
            'nodes' => Gen::nonEmptyArrayOf(
                Gen::tuple(
                    Gen::intBetween(0, 1_000),
                    Gen::intBetween(0, 1_000),
                    Gen::frequency([[1, Gen::constant(value: true)], [1, Gen::constant(value: false)]]),
                ),
                10,
            ),
            'cancelPick' => Gen::intBetween(0, 1_000),
        ];
    }

    /** @return iterable<string, array{non-empty-list<array{int, int, bool}>, int}> */
    public static function aJoinedNodeTerminatesExactlyWhenAParentDoesExamples(): iterable
    {
        // nodes 0 and 1 are the two roots; generated nodes start at 2
        yield 'a diamond cancelled at its top' => [[[0, 0, false], [2, 2, false], [2, 3, true]], 0];
        yield 'a join of two roots cancelled itself' => [[[0, 1, true]], 0];
        yield 'one branch of a join cancelled' => [[[0, 0, false], [1, 1, false], [2, 3, true]], 1];
    }
}
