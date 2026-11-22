<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\RemoveDemoProject;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\ReplayedReviewRows;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RemoveDemoProject\RemoveDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RemoveReplayedEvents;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\UnflagReplayProject;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Dashboard\Infrastructure\PublishedLiveEvents;
use Intakt\Tests\Core\Ledger\AppendOnlyTriggers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[Group('persistence')]
#[CoversClass(RemoveDemoProjectCommand::class)]
#[CoversClass(ReplayedReviewRows::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(RemoveReplayedEvents::class)]
#[UsesClass(UnflagReplayProject::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(ReplayMarkOfProject::class)]
#[UsesClass(ReplayMark::class)]
#[UsesClass(LiveBoardHeadline::class)]
#[UsesClass(LedgerReviewPages::class)]
final class RemoveDemoProjectCommandTest extends KernelTestCase
{
    private InMemoryStore $locks;

    private PublishedLiveEvents $live;

    public function testTheCommandDeletesTheDemoProjectsLedgerRowsAndItsReplayMark(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();

        $tester = $this->removal('intakt');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $this->rowsNaming('demo-round', 'demo-call'));
        self::assertSame(ReplayMark::Live, $this->markOf(CreateDemoProjectCommand::NAME));
    }

    public function testTheCommandNamesTheProjectAndHowManyRowsItRemoved(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();

        $tester = $this->removal('intakt');

        self::assertStringContainsString('Removed the demo project demo-replay with its 5 ledger rows.', $this->flat($tester));
    }

    public function testTheCommandLeavesTheRowsAndTheMarkOfOtherProjectsUntouched(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();
        $this->commands()->dispatch(new FlagProjectAsReplay('other-replay'));
        $this->givenAReview(SampleChange::PROJECT, 'sample-round', 'sample-call');
        $this->givenAReview('other-replay', 'other-round', 'other-call');

        $this->removal('intakt');

        self::assertCount(5, $this->rowsNaming('sample-round', 'sample-call'));
        self::assertCount(5, $this->rowsNaming('other-round', 'other-call'));
        self::assertSame(ReplayMark::Replay, $this->markOf('other-replay'));
    }

    public function testTheCommandKeepsAnotherProjectsRowThatNamesADemoCallUnderItsOwnRound(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();
        $live = ['call' => 'demo-call', 'phase' => 'started', 'project' => SampleChange::PROJECT, 'tier' => 'standard', 'round' => 'live-round'];
        $this->append(LedgerEventKind::UsageRecorded, $live);

        $this->removal('intakt');

        self::assertSame([json_encode($live, JSON_THROW_ON_ERROR)], array_map(static fn (RecordedEvent $row): string => $row->event->payload, iterator_to_array($this->ledger()->readInOrder(), false)));
    }

    public function testTheCommandIsRefusedOutsideTheComposeDemoAndRemovesNothing(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();

        $tester = $this->removal('');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('only on the compose demo', $this->flat($tester));
        self::assertCount(5, $this->rowsNaming('demo-round', 'demo-call'));
        self::assertSame(ReplayMark::Replay, $this->markOf(CreateDemoProjectCommand::NAME));
    }

    public function testAProjectOfTheDemoNameThatIsNoReplayKeepsItsRows(): void
    {
        $this->givenAReview(CreateDemoProjectCommand::NAME, 'demo-round', 'demo-call');

        $tester = $this->removal('intakt');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('demo-replay is not marked as a replay, so nothing is removed', $this->flat($tester));
        self::assertCount(5, $this->rowsNaming('demo-round', 'demo-call'));
    }

    public function testTheCommandIsRefusedWhileAReplayRunsAndRemovesNothing(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();
        // Kept in a variable, because a lock released by its destructor would let the removal run.
        $replay = new LockFactory($this->locks)->createLock(ReplaysRecording::LOCK);
        self::assertTrue($replay->acquire());

        $tester = $this->removal('intakt');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A replay is running', $this->flat($tester));
        self::assertCount(5, $this->rowsNaming('demo-round', 'demo-call'));
        self::assertSame(ReplayMark::Replay, $this->markOf(CreateDemoProjectCommand::NAME));
    }

    public function testRemovingTheDemoProjectAgainRemovesNothingAndSucceeds(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();
        $this->removal('intakt');

        $again = $this->removal('intakt');

        self::assertSame(Command::SUCCESS, $again->getStatusCode());
        self::assertStringContainsString('nothing is removed', $this->flat($again));
    }

    public function testTheReplayLockIsFreeAfterARemoval(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();

        $this->removal('intakt');

        self::assertTrue(new LockFactory($this->locks)->createLock(ReplaysRecording::LOCK)->acquire());
    }

    public function testRemovingTheOnlyReplayedReviewPushesTheEmptyBoardWithItsWaitingOctopus(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();

        $this->removal('intakt');

        self::assertCount(2, $this->lastPushedBoard()->filter('#board-octopus.firstday:not([hidden]) img[src*="octopus-waiting-"]'));
    }

    public function testARemovalRefusedOutsideTheComposeDemoPushesNothing(): void
    {
        $this->givenTheDemoProjectWithAReplayedReview();

        $this->removal('');

        self::assertSame([], $this->live->events);
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $entities = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        AppendOnlyTriggers::in($entities->getConnection());
        $this->locks = new InMemoryStore();
        $this->live = new PublishedLiveEvents();
    }

    private function lastPushedBoard(): Crawler
    {
        $last = array_last($this->live->events);
        self::assertNotNull($last);

        return new Crawler($last->turboStream);
    }

    private function givenTheDemoProjectWithAReplayedReview(): void
    {
        $this->commands()->dispatch(new FlagProjectAsReplay(CreateDemoProjectCommand::NAME));
        $this->givenAReview(CreateDemoProjectCommand::NAME, 'demo-round', 'demo-call');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        self::assertTrue($this->ledger()->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)))->succeeded());
    }

    private function removal(string $composeService): CommandTester
    {
        $container = self::getContainer();
        $marks = $container->get(ReplayMarks::class);
        $rows = $container->get(ReplayedReviewRows::class);
        self::assertInstanceOf(ReplayMarks::class, $marks);
        self::assertInstanceOf(ReplayedReviewRows::class, $rows);
        $application = new Application();
        $application->addCommand(new RemoveDemoProjectCommand($this->commands(), $marks, $rows, new LockFactory($this->locks), $this->headline(), $composeService));
        $tester = new CommandTester($application->find('intakt:demo:remove'));
        $tester->execute([]);

        return $tester;
    }

    private function headline(): LiveBoardHeadline
    {
        $pages = self::getContainer()->get(LedgerReviewPages::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(LedgerReviewPages::class, $pages);
        self::assertInstanceOf(Environment::class, $twig);

        return new LiveBoardHeadline($pages, $twig, $this->live, new LockFactory(new InMemoryStore()), new NullLogger());
    }

    /**
     * @return list<Uuid>
     */
    private function rowsNaming(string $round, string $call): array
    {
        $rows = array_filter(
            iterator_to_array($this->ledger()->readInOrder(), false),
            static fn (RecordedEvent $row): bool => str_contains($row->event->payload, '"'.$round.'"') || str_contains($row->event->payload, '"'.$call.'"'),
        );

        return array_values(array_map(static fn (RecordedEvent $row): Uuid => $row->id, $rows));
    }

    private function markOf(string $project): ReplayMark
    {
        $marks = self::getContainer()->get(ReplayMarks::class);
        self::assertInstanceOf(ReplayMarks::class, $marks);

        return $marks->ofProject($project);
    }

    private function ledger(): Ledger
    {
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);

        return $ledger;
    }

    private function commands(): MessageBusInterface
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);

        return $commands;
    }

    // Shaped as a replay writes them, because only the start and the started usage name the project and the removal must find the others by round or call.
    private function givenAReview(string $project, string $round, string $call): void
    {
        $this->append(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => $project, 'reviewers' => 1, 'change' => 'sample-change', 'number' => 1]);
        $this->append(LedgerEventKind::ReviewerStarted, ['round' => $round, 'reviewer' => $round.'-correctness', 'role' => 'correctness', 'tier' => 'standard']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => $project, 'tier' => 'standard', 'round' => $round, 'model' => 'claude-opus-5-5']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'settled', 'usages' => [['model' => 'claude-opus-5-5', 'total' => 10]]]);
        $this->append(LedgerEventKind::VerdictReceived, ['round' => $round, 'reviewer' => $round.'-correctness', 'role' => 'correctness', 'tier' => 'standard', 'decision' => 'approve', 'findings' => 0]);
    }

    private function flat(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
