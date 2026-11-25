<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusReconciliation;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RoundStatusMirror::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(RoundStatusLabel::class)]
#[UsesClass(LabelEvent::class)]
#[UsesClass(StatusReconciliation::class)]
final class RoundStatusMirrorTest extends TestCase
{
    use AssertsLoggedErrors;

    private const string PERSON = 'alice';
    private const string CHANGE = 'octo/app#7';
    private const string SKIPPED = 'The status label of OCTO/App#7 of alpha was left to the writer holding its lock.';

    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function testAReconcileLeavesOnlyTheStatusOfTheStoredRound(): void
    {
        $forge = new RecordingStatusLabels(['status:in-review', 'review:full']);

        $this->mirror(ReviewStatus::WaitingOnYou, $forge)->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertSame(['[status:in-review, review:full] -> [review:full, status:waiting-on-you]'], $forge->passes);
    }

    public function testASecondPassOverLabelsAlreadyRightChangesAndSaysNothing(): void
    {
        $forge = new RecordingStatusLabels(['status:in-review'], [new LabelEvent('status:waiting-on-you', self::PERSON, false)]);
        $mirror = $this->mirror(ReviewStatus::WaitingOnYou, $forge);

        $mirror->reconcile('alpha', new ChangeId(self::CHANGE));
        $mirror->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertSame([['[status:in-review] -> [status:waiting-on-you]', '[status:waiting-on-you] -> [status:waiting-on-you]'], 1], [$forge->passes, $forge->comments]);
    }

    public function testAChangeNoRoundReviewedKeepsNoStatusLabel(): void
    {
        $forge = new RecordingStatusLabels(['review:full']);

        $this->mirror(null, $forge)->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertSame(['[review:full] -> [review:full]'], $forge->passes);
    }

    public function testAWriterThatFindsThePullRequestLockedSkipsItsWriteAndLogsIt(): void
    {
        $forge = new RecordingStatusLabels(['status:in-review'], [new LabelEvent('status:in-review', self::PERSON, true)]);
        $mirror = $this->mirror(ReviewStatus::WaitingOnYou, $forge);
        $forge->whileReading(static fn () => $mirror->reconcile('alpha', new ChangeId('OCTO/App#7')));

        $mirror->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertSame([['[status:in-review] -> [status:waiting-on-you]'], 1], [$forge->passes, $forge->comments]);
        self::assertLoggedError('info', self::SKIPPED);
    }

    public function testAWriterOfAnotherPullRequestIsNotHeldByTheLock(): void
    {
        $forge = new RecordingStatusLabels(['status:in-review']);
        $mirror = $this->mirror(ReviewStatus::InReview, $forge);
        $forge->whileReading(static fn () => $mirror->reconcile('alpha', new ChangeId('octo/app#8')));

        $mirror->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertCount(2, $forge->passes);
    }

    public function testAFailedReadOfTheRoundStateWritesNothingAndIsLogged(): void
    {
        $rounds = self::createStub(LatestRoundStatus::class);
        $rounds->method('of')->willThrowException(new RuntimeException('The database is locked.'));
        $forge = new RecordingStatusLabels(['status:in-review']);

        new RoundStatusMirror($rounds, $this->locks, $this->logger(), $forge)->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertSame([[], ['status:in-review']], [$forge->passes, $forge->labels]);
        self::assertLoggedError('warning', 'The status label of octo/app#7 of alpha was not reconciled: The database is locked.');
    }

    public function testAFailedReadOfTheLabelsWritesNothingAndIsLogged(): void
    {
        $forge = new RecordingStatusLabels(['status:in-review']);
        $forge->failOnceReading(RecordingStatusLabels::LABELS);

        $this->mirror(ReviewStatus::WaitingOnYou, $forge)->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertSame([[], ['status:in-review']], [$forge->passes, $forge->labels]);
        self::assertLoggedError('warning', 'The status label of octo/app#7 of alpha was not reconciled: GitHub answered 503 to the labels read.');
    }

    public function testAReadThatFailsMidwayWritesNothingAndTheNextPassReconcilesWithOneComment(): void
    {
        $forge = new RecordingStatusLabels(['status:in-review'], [new LabelEvent('status:in-review', self::PERSON, true)]);
        $forge->failOnceReading(RecordingStatusLabels::EVENTS);
        $mirror = $this->mirror(ReviewStatus::WaitingOnYou, $forge);

        $mirror->reconcile('alpha', new ChangeId(self::CHANGE));
        $afterTheFailure = [$forge->passes, $forge->labels];
        $mirror->reconcile('alpha', new ChangeId(self::CHANGE));

        self::assertSame([[[], ['status:in-review']], ['[status:in-review] -> [status:waiting-on-you]'], 1], [$afterTheFailure, $forge->passes, $forge->comments]);
        self::assertLoggedError('warning', 'The status label of octo/app#7 of alpha was not reconciled: GitHub answered 503 to the events read.');
    }

    public function testAForgeWithoutLabelsIsLeftAlone(): void
    {
        $rounds = $this->createMock(LatestRoundStatus::class);
        $rounds->expects(self::never())->method('of');

        new RoundStatusMirror($rounds, $this->locks, $this->logger())->reconcile('alpha', new ChangeId(self::CHANGE));
    }

    private function mirror(?ReviewStatus $stored, RecordingStatusLabels $forge): RoundStatusMirror
    {
        $rounds = self::createStub(LatestRoundStatus::class);
        $rounds->method('of')->willReturn($stored);

        return new RoundStatusMirror($rounds, $this->locks, $this->logger(), $forge);
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No test log records.'));
    }
}
