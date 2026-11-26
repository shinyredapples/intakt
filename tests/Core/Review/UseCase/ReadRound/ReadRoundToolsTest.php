<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReadRound;

use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Review\Contract\ActEntryAnswer;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Core\Review\UseCase\ReadRound\GivenVerdict;
use Intakt\Core\Review\UseCase\ReadRound\ReadableRounds;
use Intakt\Core\Review\UseCase\ReadRound\ReviewerIdentity;
use Intakt\Core\Review\UseCase\ReadRound\ReviewerStatus;
use Intakt\Core\Review\UseCase\ReadRound\RoundActList;
use Intakt\Core\Review\UseCase\ReadRound\RoundActListTool;
use Intakt\Core\Review\UseCase\ReadRound\RoundFindingsResource;
use Intakt\Core\Review\UseCase\ReadRound\RoundIdentity;
use Intakt\Core\Review\UseCase\ReadRound\RoundStatus;
use Intakt\Core\Review\UseCase\ReadRound\RoundStatusTool;
use Intakt\Core\Review\UseCase\ReadRound\RoundVerdicts;
use Intakt\Core\Review\UseCase\ReadRound\RoundVerdictsTool;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Serializer\SerializerInterface;

#[Group('persistence')]
#[CoversClass(RoundStatusTool::class)]
#[CoversClass(RoundStatus::class)]
#[CoversClass(ReviewerStatus::class)]
#[CoversClass(RoundIdentity::class)]
#[CoversClass(ReviewerIdentity::class)]
#[CoversClass(RoundVerdictsTool::class)]
#[CoversClass(RoundVerdicts::class)]
#[CoversClass(GivenVerdict::class)]
#[UsesClass(ReviewerAnswer::class)]
#[CoversClass(RoundActListTool::class)]
#[CoversClass(RoundActList::class)]
#[CoversClass(RoundFindingsResource::class)]
#[CoversClass(ReadableRounds::class)]
#[UsesClass(RoundLedgerReading::class)]
#[UsesClass(DroppedFinding::class)]
#[UsesClass(StoredVerdicts::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(FlagProjectAsReplayHandler::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(ReplayProject::class)]
#[UsesClass(ReplayMark::class)]
#[UsesClass(ActEntryAnswer::class)]
#[UsesClass(FindingIds::class)]
#[UsesClass(Fence::class)]
#[UsesClass(FencedText::class)]
final class ReadRoundToolsTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testTheStatusOfARoundWaitingForItsReviewersListsThemQueued(): void
    {
        $round = $this->fixture->start(2);

        $status = $this->statusTool()($round->id()->toRfc4122());

        self::assertSame('collecting', $status->state);
        self::assertSame(['queued', 'queued'], array_map(static fn (ReviewerStatus $reviewer): string => $reviewer->state, $status->reviewers));
    }

    public function testTheStatusNamesTheRoundAndEachReviewersRoleAndTier(): void
    {
        $round = $this->fixture->start(2);
        $panel = array_map(static fn (RoundReviewer $reviewer): ReviewerIdentity => new ReviewerIdentity($reviewer->roleName(), $reviewer->tier()->value), $round->reviewers());

        $status = $this->statusTool()($round->id()->toRfc4122());

        self::assertEquals(new RoundIdentity($round->id()->toRfc4122(), RoundFixture::CHANGE, 1), $status->round);
        self::assertEquals($panel, array_map(static fn (ReviewerStatus $reviewer): ReviewerIdentity => $reviewer->reviewer, $status->reviewers));
    }

    public function testTheStatusOfAReviewedRoundIsCompleteWithItsReviewersInVerdict(): void
    {
        $round = $this->reviewedRound();

        $status = $this->statusTool()($round->id()->toRfc4122());

        self::assertSame('complete', $status->state);
        self::assertSame(['verdict', 'verdict'], array_map(static fn (ReviewerStatus $reviewer): string => $reviewer->state, $status->reviewers));
    }

    public function testTheStatusOfAJudgedRoundListsAFindingQuotingANameAbsentFromTheChangeAsDroppedWithItsReason(): void
    {
        $round = $this->judgedRoundReporting([$this->quoting('A', '`grepName` is not defined.'), $this->quoting('B', '`old` is still read.')]);

        $status = $this->statusTool()($round->id()->toRfc4122());

        self::assertSame('judged', $status->state);
        self::assertEquals([new DroppedFinding('F1', Severity::P1, '`grepName` is not defined.', 'src/Thing.php', 1, 'none of its 1 quoted code fragments is in the change')], $status->dropped);
    }

    public function testAFindingWithoutAQuoteAndASymbolAbsentFromTheChangeIsNotListedAsDropped(): void
    {
        $round = $this->judgedRoundReporting([new Finding('A', Severity::P1, 'src/Thing.php', 1, 'grepName', 'The command is built from user input.', FixableBy::Builder, false, null, null)]);

        $status = $this->statusTool()($round->id()->toRfc4122());

        self::assertSame([], $status->dropped);
        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $this->actList()($round->id()->toRfc4122())->act));
    }

    public function testTheStatusOfARoundTheJudgeHasNotAnsweredListsNoDroppedFindings(): void
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->quoting('A', '`grepName` is not defined.')], [], [])]));

        $status = $this->statusTool()($round->id()->toRfc4122());

        self::assertSame('complete', $status->state);
        self::assertSame([], $status->dropped);
    }

    public function testTheVerdictsOfAReviewedRoundCarryEachReviewersDecision(): void
    {
        $round = $this->reviewedRound();

        $verdicts = (new RoundVerdictsTool($this->readable(), new StoredVerdicts($this->serializer())))($round->id()->toRfc4122());

        self::assertSame([Decision::Changes, Decision::Approve], array_map(static fn (GivenVerdict $verdict): Decision => $verdict->verdict->verdict, $verdicts->verdicts));
    }

    public function testTheActListIsEmptyAndNotJudgedBeforeTheJudgeHasAnswered(): void
    {
        $round = $this->reviewedRound();

        $act = $this->actList()($round->id()->toRfc4122());

        self::assertFalse($act->judged);
        self::assertSame([], $act->act);
    }

    public function testTheActListKeepsAP2TheJudgeDeferred(): void
    {
        $round = $this->reviewedRound();
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer)]);
        $this->fixture->judge(new ScriptedJudgement($judgement))(new JudgeRound($round->id()->toRfc4122()));

        $act = $this->actList()($round->id()->toRfc4122());

        self::assertTrue($act->judged);
        self::assertSame(['F1', 'F2'], array_map(static fn (Finding $finding): string => $finding->id, $act->act));
    }

    public function testAnActListEntryWithoutAJudgeNoteKeepsItsShapeInTheToolAnswer(): void
    {
        $answer = new RoundActList('round', true, [$this->findingAt('A')], ReplayMark::Live);

        self::assertArrayNotHasKey('judge_note', $this->firstActEntryAsJson($answer));
    }

    public function testAnActListEntryWithAJudgeNoteShowsItFencedInTheToolAnswer(): void
    {
        $answer = new RoundActList('round', true, [$this->findingAt('A')->withJudgeNote('Rejected.')], ReplayMark::Live);

        self::assertSame('Rejected.', $this->fencedNoteOf($this->firstActEntryAsJson($answer)));
    }

    public function testTheToolAnswerKeepsItsFieldsBesideTheJudgeNote(): void
    {
        $answer = new RoundActList('round', true, [$this->findingAt('A')->withJudgeNote('Rejected.')], ReplayMark::Live);

        /** @var array<string, mixed> $data */
        $data = json_decode(json_encode($answer, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['roundId', 'judged', 'act', 'mark'], array_keys($data));
        self::assertSame(['id', 'severity', 'file', 'line', 'symbol', 'text', 'fixable_by', 'repeat', 'requirement', 'evidence', 'regression', 'gate_gap', 'title', 'kind', 'merged', 'judge_note'], array_keys($this->firstActEntryAsJson($answer)));
    }

    public function testAJudgeAnswerThatRejectsAP1LeavesItOnTheActListWithTheJudgesNote(): void
    {
        $round = $this->judgedBy(new Judgement([new JudgedFinding('F1', Mark::Reject, null, 'The branch is unreachable.'), new JudgedFinding('F2', Mark::Act)]));

        $act = $this->actList()($round->id()->toRfc4122())->act;

        self::assertSame(['F1' => 'The branch is unreachable.', 'F2' => null], $this->notesOf($act));
    }

    public function testAJudgeAnswerThatDefersAP2LeavesItOnTheActListWithTheJudgesNote(): void
    {
        $round = $this->judgedBy(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer, null, 'Needs its own design.')]));

        $act = $this->actList()($round->id()->toRfc4122())->act;

        self::assertSame(['F1' => null, 'F2' => 'Needs its own design.'], $this->notesOf($act));
    }

    public function testAP1WithoutAJudgeAnswerHasNoNoteOnTheActList(): void
    {
        $round = $this->judgedBy(new Judgement([new JudgedFinding('F2', Mark::Defer, null, 'Needs its own design.')]));

        $act = $this->actList()($round->id()->toRfc4122())->act;

        self::assertSame(['F1' => null, 'F2' => 'Needs its own design.'], $this->notesOf($act));
    }

    public function testTheJudgesNoteReachesTheToolAnswerFenced(): void
    {
        $round = $this->judgedBy(new Judgement([new JudgedFinding('F1', Mark::Reject, null, 'The branch is unreachable.')]));

        $entry = $this->firstActEntryAsJson($this->actList()($round->id()->toRfc4122()));

        self::assertSame('The branch is unreachable.', $this->fencedNoteOf($entry));
    }

    public function testTwoFindingsAtOnePlaceGiveOneActEntryThatNamesTheSecondAsMerged(): void
    {
        $round = $this->judgedWithTwoFindingsAtOnePlace();

        $act = $this->actList()($round->id()->toRfc4122());

        self::assertCount(1, $act->act);
        self::assertSame('F1', $act->act[0]->id);
        self::assertSame(['F2'], [...$act->act[0]->merged]);
    }

    public function testTheActListAnswerKeepsTheMergedIdsAsAPlainJsonList(): void
    {
        $round = $this->judgedWithTwoFindingsAtOnePlace();

        $entry = $this->firstActEntryAsJson($this->actList()($round->id()->toRfc4122()));

        self::assertSame(['F2'], $entry['merged']);
    }

    public function testTheFindingsResourceListsEveryFindingTheRoundReported(): void
    {
        $round = $this->reviewedRound();
        $id = $round->id()->toRfc4122();

        $json = $this->findingsResource()($id, 'intakt://rounds/'.$id.'/findings');

        $findings = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($findings);
        self::assertSame(['F1', 'F2'], array_column($findings, 'id'));
    }

    public function testTheFindingsResourceWritesEachFindingWithItsPropertyNamesAsJsonFields(): void
    {
        $round = $this->reviewedRound();
        $id = $round->id()->toRfc4122();

        $json = $this->findingsResource()($id, 'intakt://rounds/'.$id.'/findings');

        $findings = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($findings);
        self::assertSame([
            'id' => 'F1',
            'severity' => Severity::P1->value,
            'file' => null,
            'line' => null,
            'symbol' => null,
            'text' => 'Text of A.',
            'fixable_by' => FixableBy::Builder->value,
            'repeat' => false,
            'requirement' => null,
            'evidence' => null,
            'regression' => false,
            'gate_gap' => false,
            'title' => null,
            'kind' => null,
            'merged' => [],
        ], $findings[0]);
    }

    public function testEachToolRefusesAnUnknownRoundAsAnMcpToolError(): void
    {
        foreach ([$this->statusTool(...), $this->actList(...)] as $tool) {
            try {
                $tool()('not-a-round');
                self::fail('The unknown round was not refused.');
            } catch (ToolCallException $refused) {
                self::assertSame('No review round is stored under the id not-a-round', $refused->getMessage());
            }
        }
    }

    public function testTheVerdictsToolRefusesAnUnknownRoundAsAnMcpToolError(): void
    {
        $this->expectException(ToolCallException::class);

        (new RoundVerdictsTool($this->readable(), new StoredVerdicts($this->serializer())))('not-a-round');
    }

    public function testTheFindingsResourceRefusesAnUnknownRoundAsAResourceNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->findingsResource()('not-a-round', 'intakt://rounds/not-a-round/findings');
    }

    public function testTheStatusOfARoundInAReplayProjectCarriesTheReplayMark(): void
    {
        $this->flag('alpha');
        $round = $this->fixture->start(2);

        self::assertSame(ReplayMark::Replay, $this->statusTool()($round->id()->toRfc4122())->mark);
    }

    public function testTheStatusOfALiveRoundCarriesTheLiveMark(): void
    {
        $round = $this->fixture->start(2);

        self::assertSame(ReplayMark::Live, $this->statusTool()($round->id()->toRfc4122())->mark);
    }

    public function testTheVerdictsOfAReplayRoundCarryTheReplayMark(): void
    {
        $this->flag('alpha');
        $round = $this->reviewedRound();

        $verdicts = (new RoundVerdictsTool($this->readable(), new StoredVerdicts($this->serializer())))($round->id()->toRfc4122());

        self::assertSame(ReplayMark::Replay, $verdicts->mark);
    }

    public function testTheActListOfAReplayRoundCarriesTheReplayMark(): void
    {
        $this->flag('alpha');
        $round = $this->reviewedRound();

        self::assertSame(ReplayMark::Replay, $this->actList()($round->id()->toRfc4122())->mark);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function flag(string $project): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay($project));
    }

    private function reviewedRound(): ReviewRound
    {
        $round = $this->fixture->start(2);
        $findings = [$this->finding('A', Severity::P1), $this->finding('B', Severity::P2)];
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]);

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $this->fixture->round($round->id()->toRfc4122());
    }

    private function judgedBy(Judgement $judgement): ReviewRound
    {
        $round = $this->reviewedRound();
        $this->fixture->judge(new ScriptedJudgement($judgement))(new JudgeRound($round->id()->toRfc4122()));

        return $round;
    }

    /**
     * @param list<Finding> $act
     *
     * @return array<string, string|null>
     */
    private function notesOf(array $act): array
    {
        return array_combine(array_map(static fn (Finding $entry): string => $entry->id, $act), array_map(static fn (Finding $entry): ?string => $entry->judgeNote(), $act));
    }

    private function statusTool(): RoundStatusTool
    {
        return new RoundStatusTool($this->readable(), $this->ledgerReading());
    }

    private function actList(): RoundActListTool
    {
        return new RoundActListTool($this->readable(), $this->fixture->findingStore(), $this->ledgerReading());
    }

    private function ledgerReading(): RoundLedgerReading
    {
        return new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([]));
    }

    private function findingsResource(): RoundFindingsResource
    {
        $stored = self::getContainer()->get('serializer.stored');

        return new RoundFindingsResource($this->readable(), $this->fixture->findingStore(), $stored);
    }

    private function readable(): ReadableRounds
    {
        return new ReadableRounds($this->fixture->transactions());
    }

    private function serializer(): SerializerInterface
    {
        return self::getContainer()->get(SerializerInterface::class);
    }

    private function findingAt(string $id): Finding
    {
        return new Finding($id, Severity::P1, 'src/A.php', 7, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }

    private function judgedWithTwoFindingsAtOnePlace(): ReviewRound
    {
        $round = $this->fixture->start(2);
        $vendor = new VerdictPerRole([
            'Look at part 1.' => new Verdict(Decision::Changes, [$this->findingAt('A')], [], []),
            'Look at part 2.' => new Verdict(Decision::Changes, [$this->findingAt('B')], [], []),
        ]);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act)]);
        $this->fixture->judge(new ScriptedJudgement($judgement))(new JudgeRound($round->id()->toRfc4122()));

        return $round;
    }

    // Through json_encode, because that is how the MCP SDK writes a tool's answer.
    /**
     * @return array<string, mixed>
     */
    private function firstActEntryAsJson(RoundActList $answer): array
    {
        /** @var array{act: list<array<string, mixed>>} $data */
        $data = json_decode(json_encode($answer, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return $data['act'][0];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function fencedNoteOf(array $entry): ?string
    {
        $note = $entry['judge_note'] ?? null;
        self::assertIsString($note);

        return new Fence()->contentOf(UntrustedSource::ActList, $note);
    }

    // At one place, so a finding that is dropped would otherwise be merged with the other.
    private function quoting(string $id, string $text): Finding
    {
        return new Finding($id, Severity::P1, 'src/Thing.php', 1, null, $text, FixableBy::Builder, false, null, null);
    }

    /**
     * @param list<Finding> $findings
     */
    private function judgedRoundReporting(array $findings): ReviewRound
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]));
        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))(new JudgeRound($round->id()->toRfc4122()));

        return $this->fixture->round($round->id()->toRfc4122());
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
