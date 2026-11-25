<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\Domain\ChangeFindings;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\FindingStatus;
use Intakt\Core\Review\Domain\GoalpostRule;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Support\KernelTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[Group('persistence')]
#[CoversClass(StoredFindings::class)]
#[CoversClass(ChangeFindings::class)]
#[CoversClass(ChangeRound::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[UsesClass(RunReviewRound::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(ReviewTarget::class)]
#[UsesClass(FindingLedger::class)]
#[UsesClass(TrackedFinding::class)]
#[UsesClass(FindingStatus::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(GoalpostRule::class)]
#[UsesClass(VerdictsOfRoundHandler::class)]
#[UsesClass(PanelVerdict::class)]
final class FindingsAcrossRoundsTest extends KernelTestCase
{
    private const string CHANGE = 'feat/thing';
    private const string FIRST = 'Look at part 1.';
    private const string SECOND = 'Look at part 2.';

    private RoundFixture $fixture;

    public function testTheFirstRoundTellsNoReviewerOfOpenFindings(): void
    {
        $vendor = $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);

        self::assertSame([[], []], array_values(array_map(static fn (ReviewBrief $brief): array => $brief->open, $vendor->briefs)));
    }

    public function testEveryReviewerIsBriefedWithTheDiffStoredWithTheRound(): void
    {
        $vendor = $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);

        self::assertSame([RoundFixture::DIFF, RoundFixture::DIFF], array_values(array_map(static fn (ReviewBrief $brief): string => $brief->change->diff, $vendor->briefs)));
    }

    public function testAFirstRoundThatFoundNoLedgerBeforeAnotherRoundCommittedItBecomesTheSecondRound(): void
    {
        $later = $this->fixture->startWhileAnotherRoundCommitsAfterItsFirstLook(1);

        self::assertSame(2, $this->fixture->round($later)->number());
        self::assertCount(2, $this->fixture->storedRounds());
    }

    public function testARoundThatReadTheExistingLedgerBeforeAnotherRoundCommittedItBecomesTheThirdRound(): void
    {
        $this->fixture->start(1);

        $later = $this->fixture->startWhileAnotherRoundCommitsAfterItsFirstLook(1);

        self::assertSame(3, $this->fixture->round($later)->number());
        self::assertCount(3, $this->fixture->storedRounds());
    }

    public function testATransactionThatKeepsMeetingAnotherWritersCommitIsTriedThreeTimesThenFails(): void
    {
        $tries = 0;

        try {
            $this->fixture->transactions()->inOneTransaction(static function () use (&$tries): never {
                ++$tries;

                throw self::databaseLocked();
            });
        } catch (LockWaitTimeoutException) {
        }

        self::assertSame(3, $tries);
    }

    public function testARetryableFailureAfterTheEntityManagerClosedSurfacesAsTheOriginalFailureTriedOnce(): void
    {
        $tries = 0;
        $entities = $this->fixture->entities;
        $surfaced = null;

        try {
            $this->fixture->transactions()->inOneTransaction(static function () use (&$tries, $entities): never {
                ++$tries;
                $entities->close();

                throw self::databaseLocked();
            });
        } catch (Throwable $failure) {
            $surfaced = $failure;
        }

        self::assertInstanceOf(LockWaitTimeoutException::class, $surfaced);
        self::assertSame(1, $tries);
    }

    public function testATransactionThatFailsForAnotherReasonIsTriedOnce(): void
    {
        $tries = 0;

        try {
            $this->fixture->transactions()->inOneTransaction(static function () use (&$tries): never {
                ++$tries;

                throw new RuntimeException('Refused.');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(1, $tries);
    }

    public function testATransactionInsideACallersTransactionIsLeftToTheCallerToRetry(): void
    {
        $tries = 0;
        $connection = $this->fixture->entities->getConnection();
        $connection->beginTransaction();

        try {
            $this->fixture->transactions()->inOneTransaction(static function () use (&$tries): never {
                ++$tries;

                throw self::databaseLocked();
            });
        } catch (LockWaitTimeoutException) {
        }

        $connection->rollBack();
        self::assertSame(1, $tries);
    }

    public function testTheSecondRoundTellsEveryReviewerTheOpenFindingsUnderTheirIds(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a'), $this->finding('b')])]);

        $vendor = $this->round(self::CHANGE, []);

        self::assertSame(['F1', 'F2'], $this->openIds($vendor->briefs[self::FIRST]));
        self::assertSame(['F1', 'F2'], $this->openIds($vendor->briefs[self::SECOND]));
        self::assertSame('Text a', $vendor->briefs[self::FIRST]->open[0]->text);
    }

    public function testEveryReviewerIsToldTheNumberOfItsRound(): void
    {
        $first = $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);

        $second = $this->round(self::CHANGE, []);

        self::assertSame([1, 2, 2], [$first->briefs[self::FIRST]->round, $second->briefs[self::FIRST]->round, $second->briefs[self::SECOND]->round]);
    }

    public function testIdsStayStableOverThreeRoundsAndANewFindingGetsTheNextId(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a'), $this->finding('b')])]);
        $this->round(self::CHANGE, [self::FIRST => $this->changes(['F1'], [$this->finding('F2', true)])]);

        $third = $this->round(self::CHANGE, [self::SECOND => $this->changes([], [$this->finding('F2', true), $this->finding('c')])]);

        self::assertSame(['F2'], $this->openIds($third->briefs[self::SECOND]));
        $stored = $this->fixture->storedFindings(self::CHANGE);
        self::assertSame(['F1', 'F2', 'F3'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $stored));
        self::assertSame([FindingStatus::Resolved, FindingStatus::Open, FindingStatus::Open], array_map(static fn (TrackedFinding $tracked): FindingStatus => $tracked->status, $stored));
        self::assertSame([1, 2, 3], $stored[1]->rounds);
        self::assertSame(1, $stored[1]->firstRound());
        self::assertSame(3, $stored[1]->latestRound());
        self::assertSame([3], $stored[2]->rounds);
    }

    public function testThePanelReadsANewRoundTwoP1AsP3LikeTheLedger(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);
        $queue = new RecordingBus();
        $roundId = $this->fixture->startFor(self::CHANGE, $queue, 2);

        $this->deliver($queue, [self::FIRST => $this->changes([], [$this->finding('b', severity: Severity::P1)])]);

        $counting = $this->fixture->verdictsOf(new VerdictsOfRound($roundId, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths())))->counting;
        $findings = array_merge(...array_map(static fn (PanelVerdict $panel): array => $panel->verdict->findings, $counting));
        self::assertSame([Severity::P3], array_map(static fn (Finding $finding): Severity => $finding->severity, $findings));
        self::assertSame(Severity::P3, $this->fixture->storedFindings(self::CHANGE)[1]->latest->severity);
    }

    public function testARoundOfAnotherChangeIsToldNoneOfTheOpenFindings(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);

        $vendor = $this->round('feat/other', []);

        self::assertSame([], $vendor->briefs[self::FIRST]->open);
    }

    public function testAnOlderRoundThatCompletesAfterANewerOneCannotCloseAFindingTheNewerOneRepeated(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);
        $older = $this->started(self::CHANGE);
        $newer = $this->started(self::CHANGE);

        $this->deliver($newer, [self::FIRST => $this->changes([], [$this->finding('F1', true)])]);
        $this->deliver($older, [self::FIRST => $this->changes(['F1'], [$this->finding('b')])]);

        $stored = $this->fixture->storedFindings(self::CHANGE);
        self::assertCount(1, $stored);
        self::assertSame(FindingStatus::Open, $stored[0]->status);
        self::assertSame([1, 3], $stored[0]->rounds);
    }

    public function testANewerRoundThatCompletesAfterAnOlderOneIsRecorded(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);
        $older = $this->started(self::CHANGE);
        $newer = $this->started(self::CHANGE);

        $this->deliver($older, [self::FIRST => $this->changes([], [$this->finding('F1', true)])]);
        $this->deliver($newer, [self::FIRST => $this->changes(['F1'], [])]);

        self::assertSame(FindingStatus::Resolved, $this->fixture->storedFindings(self::CHANGE)[0]->status);
    }

    public function testTheReviewersOfAnOlderRoundAreToldTheSameOpenFindingsWhenANewerRoundCompletesBetweenThem(): void
    {
        $older = $this->started(self::CHANGE);
        $newer = $this->started(self::CHANGE);
        $vendor = new VerdictPerRole([]);

        $this->fixture->deliver($older->messages[0], $vendor);
        $this->deliver($newer, [self::FIRST => $this->changes([], [$this->finding('a')])]);
        $this->fixture->deliver($older->messages[1], $vendor);

        self::assertSame([], $this->openIds($vendor->briefs[self::FIRST]));
        self::assertSame([], $this->openIds($vendor->briefs[self::SECOND]));
    }

    public function testTheReviewersOfANewerRoundAreToldTheSameOpenFindingsWhenAnOlderRoundResolvesOneBetweenThem(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);
        $older = $this->started(self::CHANGE);
        $newer = $this->started(self::CHANGE);
        $vendor = new VerdictPerRole([self::FIRST => $this->changes([], [$this->finding('F1', true)])]);

        $this->fixture->deliver($newer->messages[0], $vendor);
        $this->deliver($older, [self::FIRST => $this->changes(['F1'], [])]);
        $this->fixture->deliver($newer->messages[1], $vendor);

        self::assertSame(['F1'], $this->openIds($vendor->briefs[self::FIRST]));
        self::assertSame(['F1'], $this->openIds($vendor->briefs[self::SECOND]));
        $stored = $this->fixture->storedFindings(self::CHANGE);
        self::assertSame(FindingStatus::Open, $stored[0]->status);
        self::assertSame([1, 3], $stored[0]->rounds);
    }

    public function testARoundStoredBeforeTheOpenFindingsWereKeptWithItIsToldNone(): void
    {
        $round = new ReviewRound(Uuid::v7(), new ReviewTarget(new ReviewSubject('alpha', __DIR__, 'Add the thing.', 'diff --git a/a b/a'), SampleIdentity::any()), new ChangeRound(self::CHANGE, 2, null));

        self::assertSame([], $this->fixture->openFindingsOf($round));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function started(string $change): RecordingBus
    {
        $queue = new RecordingBus();
        $this->fixture->startFor($change, $queue, 2);

        return $queue;
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function deliver(RecordingBus $queue, array $verdicts): VerdictPerRole
    {
        $vendor = new VerdictPerRole($verdicts);

        foreach ($queue->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $vendor;
    }

    /**
     * @return list<string>
     */
    private function openIds(ReviewBrief $brief): array
    {
        return array_map(static fn (Finding $finding): string => $finding->id, $brief->open);
    }

    private function finding(string $id, bool $repeat = false, Severity $severity = Severity::P2): Finding
    {
        return new Finding($id, $severity, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, $repeat, null, null);
    }

    /**
     * @param list<string>  $resolved
     * @param list<Finding> $findings
     */
    private function changes(array $resolved, array $findings): Verdict
    {
        return new Verdict(Decision::Changes, $findings, array_map(static fn (string $id): ResolvedFinding => new ResolvedFinding($id), $resolved), []);
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function round(string $change, array $verdicts): VerdictPerRole
    {
        return $this->deliver($this->started($change), $verdicts);
    }

    // The message of SQLite's stale snapshot, because that is the failure a retry is for.
    private static function databaseLocked(): LockWaitTimeoutException
    {
        return new LockWaitTimeoutException(Exception::new(new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')), null);
    }
}
