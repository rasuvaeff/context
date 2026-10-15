<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests\Testing;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Testing\SteppingTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FakeTimeSource::class)]
#[Covers(SteppingTimeSource::class)]
final class TestingClocksTest
{
    public function aFakeClockMovesOnlyWhenAdvanced(): void
    {
        $clock = new FakeTimeSource(5_000);

        Assert::same($clock->nowNs(), 5_000);
        Assert::same($clock->nowNs(), 5_000);

        $clock->advanceNs(10);
        $clock->advanceMs(1.5);
        $clock->sleepNs(7);

        Assert::same($clock->nowNs(), 5_000 + 10 + 1_500_000 + 7);
    }

    public function fractionalMillisecondsRoundToTheNearestNanosecond(): void
    {
        $clock = new FakeTimeSource(0);

        $clock->advanceMs(0.000_000_4);
        Assert::same($clock->nowNs(), 0);

        $clock->advanceMs(1.000_000_6);
        Assert::same($clock->nowNs(), 1_000_001);
    }

    public function aFakeClockNeverMovesBackwards(): void
    {
        $clock = new FakeTimeSource(5_000);

        $clock->advanceNs(-10);
        $clock->advanceMs(-1);
        $clock->sleepNs(-1);

        Assert::same($clock->nowNs(), 5_000);
    }

    public function aFakeClockDrivesBothDeadlinesAndSleep(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(10.0);

        $context->sleep(2.5);

        Assert::same($clock->nowNs(), 3_500_000_000);
        Assert::same($context->remainingMs(), 7_500.0);
    }

    public function aSteppingClockMovesOnEveryRead(): void
    {
        $clock = new SteppingTimeSource(100, 1_000);

        Assert::same($clock->nowNs(), 1_000);
        Assert::same($clock->nowNs(), 1_100);
        Assert::same($clock->nowNs(), 1_200);
    }

    public function aZeroStepKeepsTheClockStill(): void
    {
        $clock = new SteppingTimeSource(0);

        Assert::same($clock->nowNs(), 1_000_000_000);
        Assert::same($clock->nowNs(), 1_000_000_000);
    }
}
