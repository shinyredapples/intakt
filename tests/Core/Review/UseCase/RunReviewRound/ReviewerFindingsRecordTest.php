<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReportedFindings;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(StoredFindings::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReportedFindings::class)]
#[CoversClass(ReviewerFindingsRecorded::class)]
#[UsesClass(FindingLedger::class)]
final class ReviewerFindingsRecordTest extends KernelTestCase
{
    private const string CHANGE = 'feat/thing';
    private const string FIRST = 'Look at part 1.';
    private const string SECOND = 'Look at part 2.';

    private RoundFixture $fixture;

    public function testACompletedRoundRecordsEachReviewersFindingsUnderTheIdsItsJudgeSees(): void
    {
        $round = $this->round([self::FIRST => $this->changes($this->finding('a'), $this->finding('b')), self::SECOND => $this->changes($this->finding('c'))]);

        self::assertSame(['role-1' => ['F1', 'F2'], 'role-2' => ['F3']], $this->recordedOf($round));
        self::assertSame(['F1', 'F2', 'F3'], array_map(static fn (Finding $finding): string => $finding->id, $this->fixture->findingStore()->reportedIn($this->fixture->round($round))));
    }

    public function testTwoReviewersThatReportTheSameOwnIdAreRecordedUnderDifferentLedgerIds(): void
    {
        $round = $this->round([self::FIRST => $this->changes($this->finding('a')), self::SECOND => $this->changes($this->finding('a'))]);

        self::assertSame(['role-1' => ['F1'], 'role-2' => ['F2']], $this->recordedOf($round));
    }

    public function testAFindingReportedAgainIsRecordedUnderItsEarlierId(): void
    {
        $this->round([self::FIRST => $this->changes($this->finding('a')), self::SECOND => $this->changes($this->finding('b'))]);

        $second = $this->round([self::FIRST => $this->changes($this->finding('F2', true)), self::SECOND => $this->changes($this->finding('F2', true), $this->finding('d'))]);

        self::assertSame(['role-1' => ['F2'], 'role-2' => ['F2', 'F3']], $this->recordedOf($second));
    }

    // Decision 4 of #2981: the dashboard counts each reviewer under the severity of its own stored verdict, so the row holds it per id.
    public function testEachReviewerRecordsTheSeverityOfItsStoredVerdictPerId(): void
    {
        $round = $this->round([self::FIRST => $this->changes($this->finding('a', severity: Severity::P1)), self::SECOND => $this->changes($this->finding('b', severity: Severity::P3))]);

        self::assertSame(['role-1' => ['F1' => Severity::P1], 'role-2' => ['F2' => Severity::P3]], $this->severitiesOf($round));
    }

    // The goalpost rule gives a repeat the severity its finding was first stored with before the verdict is stored, so both repeats count at that one.
    public function testARepeatIsRecordedAtTheSeverityTheGoalpostRuleLeftInItsStoredVerdict(): void
    {
        $this->round([self::FIRST => $this->changes($this->finding('a', severity: Severity::P1)), self::SECOND => $this->changes($this->finding('b', severity: Severity::P3))]);

        $second = $this->round([self::FIRST => $this->changes($this->finding('F1', true, Severity::P3)), self::SECOND => $this->changes($this->finding('F1', true, Severity::P2))]);

        self::assertSame(['role-1' => ['F1' => Severity::P1], 'role-2' => ['F1' => Severity::P1]], $this->severitiesOf($second));
    }

    public function testAFailedReviewIsRecordedWithNoFindings(): void
    {
        $round = $this->round([self::FIRST => Verdict::noTools(), self::SECOND => $this->changes($this->finding('a'))]);

        self::assertSame(['role-1' => [], 'role-2' => ['F1']], $this->recordedOf($round));
    }

    public function testARoundStillWaitingForAReviewerRecordsNoFindingsOfAnyReviewer(): void
    {
        $queue = new RecordingBus();
        $this->fixture->startFor(self::CHANGE, $queue, 2);

        $this->fixture->deliver($queue->messages[0], new VerdictPerRole([self::FIRST => $this->changes($this->finding('a'))]));

        self::assertCount(0, $this->fixture->ledger()->readOfKind(LedgerEventKind::ReviewerFindingsRecorded));
    }

    public function testADeliveryAfterTheRoundCompletedRecordsTheFindingsOnlyOnce(): void
    {
        $queue = new RecordingBus();
        $round = $this->fixture->startFor(self::CHANGE, $queue, 2);
        $vendor = new VerdictPerRole([self::FIRST => $this->changes($this->finding('a'))]);

        foreach ([...$queue->messages, $queue->messages[1]] as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        self::assertCount(2, $this->fixture->ledger()->readOfKind(LedgerEventKind::ReviewerFindingsRecorded));
        self::assertSame(['role-1' => ['F1'], 'role-2' => []], $this->recordedOf($round));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    /**
     * @return array<string, ?array<string, Severity>>
     */
    private function severitiesOf(string $round): array
    {
        return array_map(static fn (ReviewerFindingsRecorded $payload): ?array => $payload->severities, $this->payloadsByRole($round));
    }

    private function finding(string $id, bool $repeat = false, Severity $severity = Severity::P2): Finding
    {
        return new Finding($id, $severity, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, $repeat, null, null);
    }

    private function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function round(array $verdicts): string
    {
        $queue = new RecordingBus();
        $round = $this->fixture->startFor(self::CHANGE, $queue, 2);
        $vendor = new VerdictPerRole($verdicts);

        foreach ($queue->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $round;
    }

    /**
     * @return array<string, list<string>>
     */
    private function recordedOf(string $round): array
    {
        return array_map(static fn (ReviewerFindingsRecorded $payload): array => [...$payload->findings], $this->payloadsByRole($round));
    }

    /**
     * @return array<string, ReviewerFindingsRecorded>
     */
    private function payloadsByRole(string $round): array
    {
        $roles = array_column(array_map(static fn (RoundReviewer $reviewer): array => [$reviewer->id()->toRfc4122(), $reviewer->roleName()], $this->fixture->round($round)->reviewers()), 1, 0);
        $recorded = [];

        foreach ($this->fixture->ledger()->readOfKind(LedgerEventKind::ReviewerFindingsRecorded) as $row) {
            $payload = $this->payloadOf($row);

            if ($round === $payload->round) {
                $recorded[$roles[$payload->reviewer]] = $payload;
            }
        }

        ksort($recorded);

        return $recorded;
    }

    private function payloadOf(RecordedEvent $row): ReviewerFindingsRecorded
    {
        $payload = $this->fixture->ledgerPayloads()->of($row, ReviewerFindingsRecorded::class);
        self::assertInstanceOf(ReviewerFindingsRecorded::class, $payload);

        return $payload;
    }
}
