<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\TourStage;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Journeys\Support\JourneyTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
#[Group('journeys')]
final class DemoTourJourneyTest extends JourneyTestCase
{
    private const string CHANGE = 'sample-change';
    private const string FIRST = 'demo-tour-round-1';
    private const string SECOND = 'demo-tour-round-2';
    private const string FINDING = 'The total skips the last line.';
    // Inside the queue worker's own time limit, because the recording below lasts 8 seconds, played in 2.
    private const int WORKER_SECONDS = 30;

    private string $directory = '';

    protected function tearDown(): void
    {
        if ('' !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    protected function journey(): string
    {
        return 'demo tour without a provider key';
    }

    public function testAFreshInstallWithoutAKeyPlaysTheDemoRunToTheClosedFindingsOfRound2(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('set up an empty demo project', $this->setUpAnEmptyDemoProject(...));
        $dashboard = $this->step('press Play the demo run', $this->pressPlayTheDemoRun(...));
        $this->step('let the queue worker play the run', $this->letTheWorkerPlayTheRun(...));
        $this->step('read the act list of round 1', fn () => $this->readTheActListOfRound1($dashboard));
        $this->step('read the closed findings of round 2', fn () => $this->readTheClosedFindingsOfRound2($dashboard));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    // Removed first, so a run left by an earlier journey on this instance never hides the button.
    private function setUpAnEmptyDemoProject(): void
    {
        $this->console(['intakt:demo:remove'], $this->environment());
        $this->console(['intakt:demo:create'], $this->environment());
    }

    private function pressPlayTheDemoRun(): KernelBrowser
    {
        $dashboard = $this->dashboardWith($this->environment());
        $page = $dashboard->request('GET', '/demo');

        self::assertSame('', trim($page->filter('#tour-caption')->text()));
        self::assertNotNull($page->filter('#tour-caption')->attr('hidden'));
        $page = $dashboard->submit($page->selectButton('Play the demo run')->form());

        self::assertSame('The demo run is starting: the caption at the bottom of each page names the stage it is in.', $page->filter('.flash-success')->text());

        return $dashboard;
    }

    private function letTheWorkerPlayTheRun(): void
    {
        $this->runOneQueuedMessage($this->environment(), self::WORKER_SECONDS);
    }

    private function readTheActListOfRound1(KernelBrowser $dashboard): void
    {
        $page = $dashboard->request('GET', '/reviews/'.self::FIRST.'/record');

        self::assertStringContainsString(self::FINDING, $page->filter('#round-'.self::FIRST.'-judged li.finding-card')->text());
    }

    private function readTheClosedFindingsOfRound2(KernelBrowser $dashboard): void
    {
        $page = $dashboard->request('GET', '/reviews/'.self::SECOND.'/record');

        self::assertSame('The reviewers reported no findings, so the judge had nothing to mark.', $page->filter('#round-'.self::SECOND.'-judged')->text());
        self::assertSame('Step 5 of 5', $page->filter('#tour-caption .tour-dots')->attr('aria-label'));
        self::assertSame('[id="round-'.self::SECOND.'-judged"]', $page->filter('#tour-caption')->attr('data-tour-at'));
        self::assertSame(TourStage::RoundEnds->sentence(), $page->filter('#tour-caption .tour-line')->text());
    }

    /**
     * @return array{INTAKT_COMPOSE_SERVICE: string, INTAKT_DEMO_RECORDING: string}
     */
    private function environment(): array
    {
        return ['INTAKT_COMPOSE_SERVICE' => 'intakt', 'INTAKT_DEMO_RECORDING' => $this->recording()];
    }

    // Written through RecordingFormat, because the page plays only a file intakt:demo:record could have written; the second round closes the first one's finding.
    private function recording(): string
    {
        if ('' !== $this->directory) {
            return $this->directory.'/recording.json';
        }

        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/recording.json', new RecordingFormat(StoredSerializerFactory::create())->write(new Recording(new DateTimeImmutable('2026-11-16T08:00:00+00:00'), 'dev-main', ['claude-opus-5-5'], 'sample', self::CHANGE, [
            $this->row(LedgerEventKind::ReviewStarted, ['round' => self::FIRST, 'project' => 'sample', 'reviewers' => 2, 'change' => self::CHANGE, 'number' => 1], 0),
            $this->row(LedgerEventKind::ReviewerStarted, ['round' => self::FIRST, 'reviewer' => 'r1-correctness', 'role' => 'correctness', 'tier' => 'standard'], 1),
            $this->row(LedgerEventKind::ReviewerStarted, ['round' => self::FIRST, 'reviewer' => 'r1-security', 'role' => 'security', 'tier' => 'standard'], 1),
            $this->row(LedgerEventKind::VerdictReceived, ['round' => self::FIRST, 'reviewer' => 'r1-correctness', 'role' => 'correctness', 'tier' => 'standard', 'decision' => 'changes', 'findings' => 1], 2),
            $this->row(LedgerEventKind::VerdictReceived, ['round' => self::FIRST, 'reviewer' => 'r1-security', 'role' => 'security', 'tier' => 'standard', 'decision' => 'approve', 'findings' => 0], 3),
            $this->row(LedgerEventKind::JudgeDone, ['round' => self::FIRST, 'act' => ['f1'], 'follow_ups' => [], 'findings' => [['id' => 'f1', 'severity' => 'P2', 'text' => self::FINDING]]], 4),
            $this->row(LedgerEventKind::ReviewStarted, ['round' => self::SECOND, 'project' => 'sample', 'reviewers' => 2, 'change' => self::CHANGE, 'number' => 2], 5),
            $this->row(LedgerEventKind::ReviewerStarted, ['round' => self::SECOND, 'reviewer' => 'r2-correctness', 'role' => 'correctness', 'tier' => 'standard'], 6),
            $this->row(LedgerEventKind::ReviewerStarted, ['round' => self::SECOND, 'reviewer' => 'r2-security', 'role' => 'security', 'tier' => 'standard'], 6),
            $this->row(LedgerEventKind::VerdictReceived, ['round' => self::SECOND, 'reviewer' => 'r2-correctness', 'role' => 'correctness', 'tier' => 'standard', 'decision' => 'approve', 'findings' => 0], 7),
            $this->row(LedgerEventKind::VerdictReceived, ['round' => self::SECOND, 'reviewer' => 'r2-security', 'role' => 'security', 'tier' => 'standard', 'decision' => 'approve', 'findings' => 0], 7),
            $this->row(LedgerEventKind::JudgeDone, ['round' => self::SECOND, 'act' => [], 'follow_ups' => [], 'findings' => []], 8),
        ], [])));

        return $this->directory.'/recording.json';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function row(LedgerEventKind $kind, array $payload, int $secondsAfterStart): RecordedLedgerEvent
    {
        return new RecordedLedgerEvent(sprintf('0196a000-0000-7000-8000-%012d', random_int(0, 999_999_999_999)), $kind, json_encode($payload, JSON_THROW_ON_ERROR), new DateTimeImmutable('2026-11-16T08:00:00+00:00')->modify(sprintf('+%d seconds', $secondsAfterStart)));
    }
}
