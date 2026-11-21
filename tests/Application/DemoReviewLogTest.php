<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\ReviewerForLoop\Infrastructure\ConfiguredProjectModes;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\Log\LogRecords;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

// Covers nothing because what is proven is the wiring: the application's logger, queue, bus and handlers on the shipped settings, where the demo sets no GitHub App.
#[Group('persistence')]
#[CoversNothing]
final class DemoReviewLogTest extends KernelTestCase
{
    private const array WARNING_AND_ABOVE = ['warning', 'error', 'critical', 'alert', 'emergency'];

    public function testADemoReviewWithoutAGitHubAppLeavesNoFailureAndNoTraceInTheLog(): void
    {
        $failures = $this->runADemoReview();

        self::assertSame([], $failures);
        self::assertSame([], $this->warningsAndTraces());
    }

    public function testADemoReviewWithoutAGitHubAppSaysOnceThatNothingIsPublishedToGitHub(): void
    {
        $this->runADemoReview();

        self::assertCount(1, array_filter($this->log(), static fn (array $record): bool => 'info' === $record['level'] && str_starts_with($record['message'], 'No GitHub App is configured')));
    }

    /**
     * @return list<string>
     */
    private function runADemoReview(): array
    {
        $this->judgeADemoRound();

        return $this->drainTheQueue();
    }

    // Live like the demo's project, on a local branch like its sample change, so nothing but the publication's wiring decides what the worker logs.
    private function judgeADemoRound(): void
    {
        $fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes([]));
        $vendor = new VerdictPerRole([]);
        $fixture->startFor(RoundFixture::CHANGE, $fixture->bus, 2);
        foreach ($fixture->bus->messages as $message) {
            $fixture->deliver($message, $vendor);
        }
        foreach ($fixture->judgings() as $judging) {
            $fixture->judge($vendor)($judging);
        }

        self::assertNotSame([], $this->queue()->getSent());
    }

    // The application's logger for the worker too, as messenger:consume has it, so the run's records hold the whole review's log.
    /**
     * @return list<string>
     */
    private function drainTheQueue(): array
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $bus = self::getContainer()->get('command.bus');
        $failures = [];
        $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failures): void {
            $failures[] = $event->getThrowable()->getMessage();
        });

        new Worker(['async' => $this->queue()], $bus, $events, self::getContainer()->get(LoggerInterface::class))->run(['sleep' => 0, 'stop_when_empty' => true]);

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function warningsAndTraces(): array
    {
        $flagged = [];
        foreach ($this->log() as $record) {
            if (in_array($record['level'], self::WARNING_AND_ABOVE, true) || isset($record['traced'])) {
                $flagged[] = $record['level'].': '.$record['message'];
            }
        }

        return $flagged;
    }

    /**
     * @return list<array{level: string, message: string, traced?: true}>
     */
    private function log(): array
    {
        $records = LogRecords::ofThisRun();
        self::assertNotNull($records, 'No test log records: run the tests with the UnassertedErrorLogExtension.');

        return $records->all();
    }

    private function queue(): InMemoryTransport
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        assert($queue instanceof InMemoryTransport);

        return $queue;
    }
}
