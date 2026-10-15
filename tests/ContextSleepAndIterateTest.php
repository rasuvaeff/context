<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Sleeper;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\UsleepSleeper;
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
#[Covers(UsleepSleeper::class)]
final class ContextSleepAndIterateTest
{
    public function sleepingWithoutADeadlineTakesTheFullDuration(): void
    {
        $clock = new FakeTimeSource();

        Context::background($clock)->sleep(1.5);

        Assert::same($clock->nowNs(), 2_500_000_000);
    }

    public function theDeadlineCutsASleepShort(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(1.0);

        try {
            $context->sleep(5.0);
        } catch (ContextExpiredException) {
            Assert::same($clock->nowNs(), 2_000_000_000);

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    public function aTerminatedContextDoesNotSleep(): void
    {
        $clock = new FakeTimeSource();
        [$context, $controller] = Context::background($clock)->withCancel();
        $controller->cancel();

        try {
            $context->sleep(1.0);
        } catch (ContextCancelledException) {
            Assert::same($clock->nowNs(), 1_000_000_000);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function anOversizedSleepSaturatesAndStopsAtTheDeadline(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(1.0);

        try {
            $context->sleep(1e12);
        } catch (ContextExpiredException) {
            Assert::same($clock->nowNs(), 2_000_000_000);

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    #[DataProvider('nonPositiveSleepProvider')]
    public function aNonPositiveSleepOnlyChecks(float $seconds): void
    {
        $clock = new FakeTimeSource();

        Context::background($clock)->sleep($seconds);

        Assert::same($clock->nowNs(), 1_000_000_000);
    }

    public static function nonPositiveSleepProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-3.0];
    }

    #[DataProvider('invalidSleepProvider')]
    public function invalidSleepsAreRejected(float $seconds): void
    {
        Expect::exception(\InvalidArgumentException::class);
        Context::background(new FakeTimeSource())->sleep($seconds);
    }

    public static function invalidSleepProvider(): iterable
    {
        yield 'not a number' => [NAN];
        yield 'infinite' => [INF];
    }

    public function aSleeperThatWakesEarlyIsResumed(): void
    {
        $clock = new FakeTimeSource();
        $sleeper = new class ($clock) implements Sleeper {
            public int $calls = 0;

            public function __construct(private readonly FakeTimeSource $clock) {}

            #[\Override]
            public function sleepNs(int $nanoseconds): void
            {
                $this->calls++;
                $this->clock->advanceNs(intdiv($nanoseconds, 2) + 1);
            }
        };

        Context::background($clock, $sleeper)->sleep(1.0);

        Assert::true($sleeper->calls > 1);
        Assert::true($clock->nowNs() >= 2_000_000_000);
    }

    public function aCancellationRequestedDuringSleepEndsIt(): void
    {
        $clock = new FakeTimeSource();
        // Stands in for a signal handler that wakes usleep() early.
        $sleeper = new class ($clock) implements Sleeper {
            public ?ContextController $controller = null;

            public function __construct(private readonly FakeTimeSource $clock) {}

            #[\Override]
            public function sleepNs(int $nanoseconds): void
            {
                $this->clock->advanceNs(intdiv($nanoseconds, 4));
                $this->controller?->requestCancel(new \RuntimeException('SIGTERM'));
            }
        };
        [$context, $sleeper->controller] = Context::background($clock, $sleeper)->withCancel();

        try {
            $context->sleep(4.0);
        } catch (ContextCancelledException $exception) {
            Assert::same($exception->getPrevious()?->getMessage(), 'SIGTERM');
            Assert::same($clock->nowNs(), 2_000_000_000);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function theRealSleeperWaitsAtLeastTheDuration(): void
    {
        $started = hrtime(as_number: true);

        Context::background()->sleep(0.002);

        Assert::true(hrtime(as_number: true) - $started >= 2_000_000);
    }

    public function theRealSleeperBlocks(): void
    {
        $started = hrtime(as_number: true);

        (new UsleepSleeper())->sleepNs(3_000_000);

        Assert::true(hrtime(as_number: true) - $started >= 2_000_000);
    }

    public function aSleepRoundsToTheNearestNanosecond(): void
    {
        $clock = new FakeTimeSource();

        Context::background($clock)->sleep(1.3e-9);

        Assert::same($clock->nowNs(), 1_000_000_001);
    }

    public function theRealSleeperIgnoresNonPositiveDurations(): void
    {
        $started = hrtime(as_number: true);

        (new UsleepSleeper())->sleepNs(0);
        (new UsleepSleeper())->sleepNs(-1_000_000_000);

        Assert::true(hrtime(as_number: true) - $started < 500_000_000);
    }

    #[Property(runs: 300)]
    public function aSleepStopsAtWhicheverComesFirst(int $sleepMs, int $deadlineMs): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout($deadlineMs / 1000.0);
        $expired = false;

        try {
            $context->sleep($sleepMs / 1000.0);
        } catch (ContextExpiredException) {
            $expired = true;
        }

        Assert::same($clock->nowNs() - 1_000_000_000, min($sleepMs, $deadlineMs) * 1_000_000);
        Assert::same($expired, $deadlineMs <= $sleepMs);

        Classify::cover($expired, 'the deadline came first', 20);
        Classify::cover(!$expired, 'the sleep completed', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aSleepStopsAtWhicheverComesFirstGenerators(): array
    {
        return [
            'sleepMs' => Gen::intBetween(0, 3_000),
            'deadlineMs' => Gen::intBetween(1, 3_000),
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function aSleepStopsAtWhicheverComesFirstExamples(): iterable
    {
        yield 'the deadline exactly at the end of the sleep' => [1_000, 1_000];
        yield 'one millisecond to spare' => [999, 1_000];
        yield 'a zero sleep' => [0, 1];
    }

    public function iteratingKeepsKeysIncludingDuplicates(): void
    {
        $source = (static function (): \Generator {
            yield 'a' => 1;
            yield 'a' => 2;
            yield 7 => 3;
        })();
        $seen = [];

        foreach (Context::background()->iterate($source) as $key => $value) {
            $seen[] = [$key, $value];
        }

        Assert::same($seen, [['a', 1], ['a', 2], [7, 3]]);
    }

    public function iteratingAcceptsArraysAndAggregates(): void
    {
        $aggregate = new \ArrayObject(['x' => 1, 'y' => 2]);

        Assert::same(iterator_to_array(Context::background()->iterate(['p' => 1, 'q' => 2])), ['p' => 1, 'q' => 2]);
        Assert::same(iterator_to_array(Context::background()->iterate($aggregate)), ['x' => 1, 'y' => 2]);
    }

    public function anEmptyIterationStillChecks(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();

        Expect::exception(ContextCancelledException::class);
        foreach ($context->iterate([]) as $unused) {
            throw new \LogicException('nothing to iterate');
        }
    }

    public function anInvalidCheckIntervalIsRejectedBeforeIterating(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        Context::background()->iterate([1], every: 0);
    }

    public function aCheckIntervalSkipsChecksBetweenElements(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(0.0025);
        $processed = [];

        try {
            foreach ($context->iterate([1, 2, 3, 4, 5, 6], every: 3) as $item) {
                $processed[] = $item;
                $clock->advanceMs(1);
            }
        } catch (ContextExpiredException) {
            Assert::same($processed, [1, 2, 3]);

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    #[Property(runs: 300)]
    public function iterationStopsBeforePullingPastTheDeadline(int $items, int $deadlineMs): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout($deadlineMs / 1000.0);
        $pulled = 0;
        $source = (static function () use ($items, &$pulled): \Generator {
            for ($i = 1; $i <= $items; $i++) {
                $pulled++;

                yield $i;
            }
        })();
        $processed = 0;
        $expired = false;

        try {
            foreach ($context->iterate($source) as $item) {
                $processed++;
                $clock->advanceMs(1);
            }
        } catch (ContextExpiredException) {
            $expired = true;
        }

        Assert::same($processed, min($items, $deadlineMs));
        Assert::same($pulled, $processed);
        Assert::same($expired, $deadlineMs <= $items);

        Classify::cover($expired, 'stopped by the deadline', 20);
        Classify::cover(!$expired, 'iterated everything', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function iterationStopsBeforePullingPastTheDeadlineGenerators(): array
    {
        return [
            'items' => Gen::intBetween(0, 20),
            'deadlineMs' => Gen::intBetween(0, 25),
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function iterationStopsBeforePullingPastTheDeadlineExamples(): iterable
    {
        yield 'expired before the first element' => [5, 0];
        yield 'the deadline at the last element' => [5, 5];
        yield 'an empty source' => [0, 3];
    }
}
