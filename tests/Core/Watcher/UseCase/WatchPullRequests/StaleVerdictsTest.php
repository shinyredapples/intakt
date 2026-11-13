<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\UseCase\MarkVerdictsStale\MarkVerdictsStaleHandler;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\DiscardedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StaleVerdicts;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StartedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

// Runs Review's own handler behind the installed command bus, because the defect was a stale mark that only the Watcher saw.
#[Group('persistence')]
#[CoversClass(StaleVerdicts::class)]
#[UsesClass(MarkVerdictsStaleHandler::class)]
#[UsesClass(MarkVerdictsStale::class)]
#[UsesClass(StartedRounds::class)]
#[UsesClass(StartedRound::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(DiscardedRounds::class)]
#[UsesClass(WatcherLedger::class)]
#[UsesClass(WatchedProject::class)]
final class StaleVerdictsTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAFailedBootMakesReviewAnswerThatTheVerdictsOfTheRoundAtThatHeadAreStale(): void
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();
        $open = new OpenChange(new ChangeId('7'), new CommitId(SampleIdentity::HEAD), new DateTimeImmutable('2026-11-13T10:00:00Z'));
        $this->fixture->entities->persist(new StartedRound('alpha', $open, $round));
        $this->fixture->entities->flush();

        $this->staleVerdicts()->noteFailedBoot(new WatchedProject('alpha', new ChangeSource('repo', 'main'), 'Review it.'), $open);

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, [])));
        self::assertSame(Standing::Stale, $verdicts->standing->standing);
    }

    public function testAHeadThatReturnsAndFailsToBootMakesItsEarlierRoundStaleInReviewTooWhenTheWatcherHadAlreadyMarkedIt(): void
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();
        $returning = new OpenChange(new ChangeId('7'), new CommitId(SampleIdentity::HEAD), new DateTimeImmutable('2026-11-13T10:00:00Z'));
        $this->fixture->entities->persist(new StartedRound('alpha', $returning, $round));
        $this->fixture->entities->flush();
        $moved = new StandingQueryBus($this->fixture->identityAt(SampleIdentity::HEAD, 2), $this->fixture->entities);
        $moved->move = new HeadMove(HeadMoveKind::Rewritten, []);
        $project = new WatchedProject('alpha', new ChangeSource('repo', 'main'), 'Review it.');
        $stale = $this->staleVerdicts($moved);
        $stale->noteMovedHead($project, new OpenChange(new ChangeId('7'), new CommitId(str_repeat('b', 40)), new DateTimeImmutable('2026-11-13T10:05:00Z')));

        $stale->noteFailedBoot($project, $returning);

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, [])));
        self::assertSame(Standing::Stale, $verdicts->standing->standing);
    }

    public function testAFailedBootMakesStaleTheApprovalsOfAnEarlierHeadThatReviewKeptAcrossADocumentationOnlyMove(): void
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();
        $this->fixture->entities->persist(new StartedRound('alpha', new OpenChange(new ChangeId('7'), new CommitId(SampleIdentity::HEAD), new DateTimeImmutable('2026-11-13T10:00:00Z')), $round));
        $this->fixture->entities->flush();
        $documentationOnly = new StandingQueryBus($this->fixture->identityAt(SampleIdentity::HEAD, 2), $this->fixture->entities);
        $documentationOnly->move = new HeadMove(HeadMoveKind::Commits, ['docs/user/README.md']);
        $project = new WatchedProject('alpha', new ChangeSource('repo', 'main'), 'Review it.');
        $failing = new OpenChange(new ChangeId('7'), new CommitId(SampleIdentity::NEW_HEAD), new DateTimeImmutable('2026-11-13T10:05:00Z'));
        $stale = $this->staleVerdicts($documentationOnly);
        $stale->noteMovedHead($project, $failing);

        $stale->noteFailedBoot($project, $failing);

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::NEW_HEAD, 2), $documentationOnly->move));
        self::assertSame(Standing::Stale, $verdicts->standing->standing);
    }

    private function staleVerdicts(?MessageBusInterface $queries = null): StaleVerdicts
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);
        $entities = $this->fixture->entities;
        $rounds = new StartedRounds($entities, new PendingHeads($entities, new MockClock('2026-11-13T10:00:00Z')), new DiscardedRounds($commands, new NullLogger()));

        return new StaleVerdicts($queries ?? self::createStub(MessageBusInterface::class), $commands, $rounds, new WatcherLedger(new InMemoryLedger()));
    }
}
