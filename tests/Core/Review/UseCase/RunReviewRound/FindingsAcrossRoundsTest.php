<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeFindings;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\FindingStatus;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\SampleIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

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
final class FindingsAcrossRoundsTest extends KernelTestCase
{
    private const string CHANGE = 'feat/thing';
    private const string FIRST = 'Look at part 1.';
    private const string SECOND = 'Look at part 2.';

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testTheFirstRoundTellsNoReviewerOfOpenFindings(): void
    {
        $vendor = $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);

        self::assertSame([[], []], array_values(array_map(static fn (ReviewBrief $brief): array => $brief->open, $vendor->briefs)));
    }

    public function testTheSecondRoundTellsEveryReviewerTheOpenFindingsUnderTheirIds(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a'), $this->finding('b')])]);

        $vendor = $this->round(self::CHANGE, []);

        self::assertSame(['F1', 'F2'], $this->openIds($vendor->briefs[self::FIRST]));
        self::assertSame(['F1', 'F2'], $this->openIds($vendor->briefs[self::SECOND]));
        self::assertSame('Text a', $vendor->briefs[self::FIRST]->open[0]->text);
    }

    public function testAVerdictWithResolvedIdsClosesThemAndARepeatKeepsItsId(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a'), $this->finding('b')])]);

        $this->round(self::CHANGE, [self::FIRST => $this->changes(['F1'], [$this->finding('F2', true)])]);

        $stored = $this->fixture->storedFindings(self::CHANGE);
        self::assertSame(['F1', 'F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $stored));
        self::assertSame(FindingStatus::Resolved, $stored[0]->status);
        self::assertSame(FindingStatus::Open, $stored[1]->status);
        self::assertSame([1, 2], $stored[1]->rounds);
    }

    public function testIdsStayStableOverThreeRoundsAndANewFindingGetsTheNextId(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a'), $this->finding('b')])]);
        $this->round(self::CHANGE, [self::FIRST => $this->changes(['F1'], [$this->finding('F2', true)])]);

        $third = $this->round(self::CHANGE, [self::SECOND => $this->changes([], [$this->finding('F2', true), $this->finding('c')])]);

        self::assertSame(['F2'], $this->openIds($third->briefs[self::SECOND]));
        $stored = $this->fixture->storedFindings(self::CHANGE);
        self::assertSame(['F1', 'F2', 'F3'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $stored));
        self::assertSame([1, 2, 3], $stored[1]->rounds);
        self::assertSame(1, $stored[1]->firstRound());
        self::assertSame(3, $stored[1]->latestRound());
        self::assertSame([3], $stored[2]->rounds);
    }

    public function testARoundOfAnotherChangeIsToldNoneOfTheOpenFindings(): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);

        $vendor = $this->round('feat/other', []);

        self::assertSame([], $vendor->briefs[self::FIRST]->open);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bothReviewerOrders(): iterable
    {
        yield 'first resolves, second repeats' => [self::FIRST, self::SECOND];
        yield 'second resolves, first repeats' => [self::SECOND, self::FIRST];
    }

    #[DataProvider('bothReviewerOrders')]
    public function testAFindingOneReviewerRepeatsStaysOpenWhenAnotherResolvesIt(string $resolving, string $repeating): void
    {
        $this->round(self::CHANGE, [self::FIRST => $this->changes([], [$this->finding('a')])]);

        $this->round(self::CHANGE, [$resolving => $this->changes(['F1'], []), $repeating => $this->changes([], [$this->finding('F1', true)])]);

        $stored = $this->fixture->storedFindings(self::CHANGE);
        self::assertSame(FindingStatus::Open, $stored[0]->status);
        self::assertSame([1, 2], $stored[0]->rounds);
    }

    public function testAFailedReviewInARoundAddsNoFindingToTheChange(): void
    {
        $this->round(self::CHANGE, [self::FIRST => Verdict::invalidOutput('the reviewer made a malformed tool call.')]);

        self::assertSame([], $this->fixture->storedFindings(self::CHANGE));
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
        $round = new ReviewRound(Uuid::v7(), new ReviewTarget(new ReviewSubject('alpha', __DIR__, 'Add the thing.'), SampleIdentity::any()), new ChangeRound(self::CHANGE, 2, null));

        self::assertSame([], $this->fixture->openFindingsOf($round));
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function round(string $change, array $verdicts): VerdictPerRole
    {
        return $this->deliver($this->started($change), $verdicts);
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

    /**
     * @param list<string>  $resolved
     * @param list<Finding> $findings
     */
    private function changes(array $resolved, array $findings): Verdict
    {
        return new Verdict(Decision::Changes, $findings, $resolved, []);
    }

    private function finding(string $id, bool $repeat = false): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, $repeat, null, null);
    }
}
