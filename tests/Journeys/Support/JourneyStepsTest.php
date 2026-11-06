<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JourneySteps::class)]
final class JourneyStepsTest extends TestCase
{
    public function testAPassingStepReturnsItsValue(): void
    {
        $steps = new JourneySteps('first install');

        self::assertSame(42, $steps->run('count', static fn (): int => 42));
    }

    public function testAFailingAssertionNamesTheJourneyAndTheStep(): void
    {
        $steps = new JourneySteps('first install');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/^journey "first install" failed at step "open the dashboard": the page is empty$/');

        $steps->run('open the dashboard', static fn () => self::fail('the page is empty'));
    }

    public function testAnErrorInAStepNamesTheStepToo(): void
    {
        $steps = new JourneySteps('first install');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/^journey "first install" failed at step "seed": no disk$/');

        $steps->run('seed', static fn () => throw new RuntimeException('no disk'));
    }
}
