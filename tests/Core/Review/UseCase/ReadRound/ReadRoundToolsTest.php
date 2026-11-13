<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReadRound;

use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
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
#[CoversClass(RoundActListTool::class)]
#[CoversClass(RoundActList::class)]
#[CoversClass(RoundFindingsResource::class)]
#[CoversClass(ReadableRounds::class)]
#[UsesClass(RoundLedgerReading::class)]
#[UsesClass(StoredVerdicts::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(ReviewRound::class)]
final class ReadRoundToolsTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

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

    public function testTheVerdictsOfAReviewedRoundCarryEachReviewersDecision(): void
    {
        $round = $this->reviewedRound();

        $verdicts = (new RoundVerdictsTool($this->readable(), new StoredVerdicts($this->serializer())))($round->id()->toRfc4122());

        self::assertSame([Decision::Changes, Decision::Approve], array_map(static fn (PanelVerdict $verdict): Decision => $verdict->verdict->verdict, $verdicts->verdicts));
    }

    public function testTheActListIsEmptyAndNotJudgedBeforeTheJudgeHasAnswered(): void
    {
        $round = $this->reviewedRound();

        $act = $this->actList()($round->id()->toRfc4122());

        self::assertFalse($act->judged);
        self::assertSame([], $act->act);
    }

    public function testTheActListHoldsOnlyTheFindingsTheJudgeMarkedAct(): void
    {
        $round = $this->reviewedRound();
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer)]);
        $this->fixture->judge(new ScriptedJudgement($judgement))(new JudgeRound($round->id()->toRfc4122()));

        $act = $this->actList()($round->id()->toRfc4122());

        self::assertTrue($act->judged);
        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $act->act));
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

    private function statusTool(): RoundStatusTool
    {
        return new RoundStatusTool($this->readable());
    }

    private function actList(): RoundActListTool
    {
        return new RoundActListTool($this->readable(), $this->fixture->findingStore(), new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([])));
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

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
