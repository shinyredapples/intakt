<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\JourneysGate;
use Intakt\Gates\Options;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(JourneysGate::class)]
#[UsesClass(CheckCommand::class)]
#[UsesClass(Options::class)]
final class JourneysGateTest extends SandboxTestCase
{
    public function testAPassingJourneyPassesTheGate(): void
    {
        $run = $this->sandboxWith('journeys-passing')->check('--journeys');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('journeys'), $run->output());
    }

    public function testABrokenStepFailsOnlyItsJourneyAndNamesTheStep(): void
    {
        $run = $this->sandboxWith('journeys-one-broken')->check('--journeys');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('journeys'), $run->output());
        self::assertStringContainsString('BrokenJourneyTest.php: journey "broken" failed at step "read the second page"', $run->findingsOf('journeys'));
        self::assertStringNotContainsString('CalmJourneyTest', $run->findingsOf('journeys'));
    }

    public function testATreeWithoutJourneysFailsTheGate(): void
    {
        $run = $this->emptySandbox()->check('--journeys');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('no journey found', $run->findingsOf('journeys'));
    }

    public function testJourneysDoNotRunTogetherWithOtherOptions(): void
    {
        $run = $this->emptySandbox()->check('--journeys', '--fast');

        self::assertSame(2, $run->exitCode());
        self::assertStringContainsString('--journeys runs alone', $run->output());
    }

    public function testTheJourneysGateIsNotOneOfTheGatesAChangePasses(): void
    {
        $run = $this->emptySandbox()->check('--only=journeys');

        self::assertSame(2, $run->exitCode());
        self::assertStringContainsString('unknown gate journeys', $run->output());
    }
}
