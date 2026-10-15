<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\Internal\ContextState;
use Rasuvaeff\Context\TerminationCause;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ContextController::class)]
#[Covers(ContextState::class)]
#[Covers(Context::class)]
final class ContextRequestCancelTest
{
    public function aRequestRunsNothingUntilTheContextIsChecked(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $calls = 0;
        $context->onTerminate(static function () use (&$calls): void {
            $calls++;
        });

        $controller->requestCancel();

        Assert::same($calls, 0);
        Assert::true($context->isCancelled());
        Assert::same($calls, 1);
    }

    public function theReasonOfARequestIsKept(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $reason = new \RuntimeException('SIGTERM');
        $controller->requestCancel($reason);

        Expect::exception(ContextCancelledException::class);

        try {
            $context->assertActive();
        } catch (ContextCancelledException $exception) {
            Assert::same($exception->getPrevious(), $reason);

            throw $exception;
        }
    }

    public function theFirstRequestWins(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $first = new \RuntimeException('first');
        $controller->requestCancel($first);
        $controller->requestCancel(new \RuntimeException('second'));
        $reasons = [];
        $context->onTerminate(static function (TerminationCause $cause) use (&$reasons): void {
            $reasons[] = $cause->reason();
        });

        $context->isTerminated();

        Assert::same($reasons, [$first]);
    }

    public function anImmediateCancelOverridesAPendingRequest(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $immediate = new \RuntimeException('immediate');
        $controller->requestCancel(new \RuntimeException('pending'));
        $reasons = [];
        $context->onTerminate(static function (TerminationCause $cause) use (&$reasons): void {
            $reasons[] = $cause->reason();
        });

        $controller->cancel($immediate);
        $context->isTerminated();

        Assert::same($reasons, [$immediate]);
    }

    public function aRequestOnATerminatedContextIsIgnored(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $first = new \RuntimeException('first');
        $controller->cancel($first);
        $controller->requestCancel(new \RuntimeException('late'));

        try {
            $context->assertActive();
        } catch (ContextCancelledException $exception) {
            Assert::same($exception->getPrevious(), $first);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function checkingADescendantAppliesTheRequest(): void
    {
        [$parent, $controller] = Context::background()->withCancel();
        [$child] = $parent->withCancel();
        [$sibling] = Context::background()->withCancel();
        $parentCalls = 0;
        $parent->onTerminate(static function () use (&$parentCalls): void {
            $parentCalls++;
        });

        $controller->requestCancel();
        $sibling->isTerminated();

        Assert::same($parentCalls, 0);
        Assert::true($child->isCancelled());
        Assert::same($parentCalls, 1);
    }

    public function aRequestWinsOverADeadlineDiscoveredAtTheSameCheck(): void
    {
        $clock = new FakeTimeSource();
        [$context, $controller] = Context::background($clock)->withTimeout(1.0);
        $controller->requestCancel();
        $clock->advanceMs(2_000);

        Assert::true($context->isCancelled());
        Assert::false($context->isExpired());
    }

    public function aFailingListenerSurfacesFromTheCheck(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        [$child] = $context->withCancel();
        $context->onTerminate(static function (): void {
            throw new \DomainException('listener failed');
        });

        $controller->requestCancel();

        try {
            $child->assertActive();
        } catch (\DomainException $exception) {
            Assert::same($exception->getMessage(), 'listener failed');
            Assert::true($child->isCancelled());
            Assert::true($context->isCancelled());

            return;
        }

        throw new \LogicException('the listener failure was expected to surface');
    }

    public function everyAppliedOrOverriddenRequestReleasesTheFastPath(): void
    {
        $before = $this->pendingRequests();
        [$first, $firstController] = Context::background()->withCancel();
        [$second, $secondController] = Context::background()->withCancel();
        [, $terminatedController] = Context::background()->withCancel();
        $terminatedController->cancel();

        $firstController->requestCancel();
        $firstController->requestCancel();
        $secondController->requestCancel();
        $terminatedController->requestCancel();

        Assert::same($this->pendingRequests(), $before + 2);

        $first->isTerminated();
        $secondController->cancel();

        Assert::same($this->pendingRequests(), $before);
        Assert::true($second->isCancelled());
    }

    public function aRequestReachesAJoinedContextThroughEitherParent(): void
    {
        [$worker, $workerController] = Context::background()->withCancel();
        [$message] = Context::background()->withCancel();
        [$joined] = $message->joinedWith($worker);

        $workerController->requestCancel();

        Assert::true($joined->isCancelled());
        Assert::false($message->isTerminated());
    }

    /**
     * @param non-empty-list<array{int, bool}> $nodes [parent pick, requests cancellation]
     */
    #[Property(runs: 300)]
    public function aCheckAppliesTheTopmostRequestOnItsPath(array $nodes, int $observePick): void
    {
        [$root] = Context::background()->withCancel();
        $contexts = [];
        $parents = [];
        $requested = [];
        $terminated = [];

        foreach ($nodes as $i => [$pick, $requests]) {
            $parent = $i === 0 ? -1 : $pick % $i;
            [$context, $controller] = ($parent === -1 ? $root : $contexts[$parent])->withCancel();
            $context->onTerminate(static function () use (&$terminated, $i): void {
                $terminated[] = $i;
            });
            if ($requests) {
                $controller->requestCancel();
                $requested[] = $i;
            }
            $contexts[] = $context;
            $parents[] = $parent;
        }

        $observed = $observePick % \count($contexts);
        $path = [];
        for ($node = $observed; $node !== -1; $node = $parents[$node]) {
            array_unshift($path, $node);
        }
        $topmost = null;
        foreach ($path as $node) {
            if (\in_array($node, $requested, strict: true)) {
                $topmost = $node;

                break;
            }
        }

        $contexts[$observed]->isTerminated();
        sort($terminated);

        Assert::same($terminated, $topmost === null ? [] : $this->subtree($parents, $topmost));

        Classify::cover($topmost !== null, 'a request on the path', 20);
        Classify::cover($topmost === null, 'no request on the path', 10);
        Classify::when($topmost !== null && $topmost !== $observed, 'an ancestor requested');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aCheckAppliesTheTopmostRequestOnItsPathGenerators(): array
    {
        return [
            'nodes' => Gen::nonEmptyArrayOf(
                Gen::tuple(
                    Gen::intBetween(0, 1_000),
                    Gen::frequency([[3, Gen::constant(value: false)], [1, Gen::constant(value: true)]]),
                ),
                10,
            ),
            'observePick' => Gen::intBetween(0, 1_000),
        ];
    }

    /** @return iterable<string, array{non-empty-list<array{int, bool}>, int}> */
    public static function aCheckAppliesTheTopmostRequestOnItsPathExamples(): iterable
    {
        yield 'two requests on one path' => [[[0, true], [0, true], [1, false]], 2];
        yield 'a request on a sibling branch only' => [[[0, false], [0, true], [0, false]], 2];
        yield 'the observed node requested' => [[[0, false], [0, true]], 1];
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

    private function pendingRequests(): int
    {
        $count = (new \ReflectionProperty(ContextState::class, 'pendingRequests'))->getValue();

        return \is_int($count) ? $count : -1;
    }
}
