<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

#[CoversNothing]
#[Group('journeys')]
final class LiveReviewProgressBreakJourneyTest extends JourneyTestCase
{
    private const string JOURNEY = 'live review progress on the dashboard';
    private const string BROKEN_STEP = 'open the running round';
    private const string LATER_STEP = 'step the round until the judge has ruled';
    private const string MISSING_PREFIX = '/forced-break';
    // The watcher tick can take up to 90 seconds, so this stays under the gate's 240 seconds for a journey.
    private const int CHILD_SECONDS = 200;

    public function testABrokenDashboardAddressFailsTheLiveReviewProgressJourneyAtTheStepThatOpensTheRunningRound(): void
    {
        $output = $this->step('run the live review progress journey with a dashboard address that does not exist', $this->runTheBrokenJourney(...));

        $this->step('read that only the step that opens the running round is named', fn () => $this->assertOnlyTheBrokenStepIsNamed($output));
    }

    protected function journey(): string
    {
        return 'forced break in the live review progress journey';
    }

    private function runTheBrokenJourney(): string
    {
        $root = __DIR__.'/../..';
        $process = new Process(
            [PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-configuration', '--group=journeys', '--bootstrap='.$root.'/vendor/autoload.php', __DIR__.'/LiveReviewProgressJourneyTest.php'],
            $root,
            ['INTAKT_INSTANCE_URL' => $this->address().self::MISSING_PREFIX],
            null,
            self::CHILD_SECONDS,
        );
        $process->run();

        self::assertFalse($process->isSuccessful(), 'the journey passed although its dashboard address was broken');

        return $process->getOutput().$process->getErrorOutput();
    }

    private function assertOnlyTheBrokenStepIsNamed(string $output): void
    {
        self::assertStringContainsString('journey "'.self::JOURNEY.'" failed at step "'.self::BROKEN_STEP.'": GET /reviews/', $output);
        self::assertSame(1, substr_count($output, ' failed at step "'), 'more than one step failed');
        self::assertStringContainsString('Tests: 1,', $output, 'more than the one journey ran');
        self::assertStringNotContainsString(self::LATER_STEP, $output);
    }
}
