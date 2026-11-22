<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Tests\Core\Ai\Infrastructure\FailingWhenRead;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;

// Plan T25: a long run, a reviewer that keeps asking for tools or a provider that refuses late in the conversation, must end as changes or blocked with a reason; scripted by call counters through the real vendor stack, so no run waits.
#[Group('persistence')]
#[CoversClass(ReviewerAgent::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(UsageLedger::class)]
#[CoversClass(ReviewChange::class)]
#[UsesClass(AccountLimitedVendor::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
final class LongRunTest extends KernelTestCase
{
    private const int TOOL_ROUNDS = 5;

    private ScriptedRound $run;

    protected function setUp(): void
    {
        $this->run = new ScriptedRound(new RoundFixture(self::getContainer()), self::getContainer());
    }

    public function testAReviewerStillAskingForToolsAtTheRoundLimitOnItsRetryTooFailsAndBlocksTheRoundWithThatReason(): void
    {
        $review = $this->stillAskingForTools();

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame(['role-1 reviewer failed, the reviewer\'s answer could not be read as a verdict: the reviewer was still asking for tools after 30 rounds of tool calls.'], [...$review->reasons]);
        self::assertSame([], $review->verdicts);
    }

    // Issue #1918: the reason is Intakt's own wording, so the ledger keeps it instead of only the class.
    public function testAReviewerStillAskingForToolsAtTheRoundLimitIsRecordedInTheLedgerWithThatReason(): void
    {
        $this->stillAskingForTools();

        self::assertSame(['the reviewer\'s answer could not be read as a verdict: the reviewer was still asking for tools after 30 rounds of tool calls.'], $this->run->payloadValues(LedgerEventKind::ReviewerFailed, 'failure'));
    }

    public function testTheCallOfARunThatHitTheRoundLimitAndTheCallOfItsRetryAreEachSettledOnce(): void
    {
        $this->stillAskingForTools();

        self::assertSame([['started', 'settled'], ['started', 'settled']], array_values($this->run->usagePhasesByCall()));
        self::assertSame([ReviewerRuns::MAX_ROUNDS + 1, ReviewerRuns::MAX_ROUNDS + 1], $this->usageRowsOfEndingEvents());
    }

    public function testAProviderLimitAfterManyToolRoundsOnTheTierAndItsSubstituteEndsTheRoundBlockedWithTheReasonOfTheReviewer(): void
    {
        $review = $this->limitedAfterToolRounds();

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertCount(1, [...$review->reasons]);
        self::assertMatchesRegularExpression('/^role-1 reviewer blocked, Every provider of the tier is out: anthropic for \d+ s, openai for \d+ s\.$/', [...$review->reasons][0]);
    }

    public function testTheBlockedReviewerOfALongRunRecordsItsReasonInTheLedger(): void
    {
        $this->limitedAfterToolRounds();

        $reasons = $this->run->payloadValues(LedgerEventKind::ReviewerBlocked, 'reason');
        self::assertCount(1, $reasons);
        self::assertIsString($reasons[0]);
        self::assertMatchesRegularExpression('/^Every provider of the tier is out: anthropic for \d+ s, openai for \d+ s\.$/', $reasons[0]);
    }

    public function testEachCallALateLimitInterruptedIsLeftUnsettledOnceAndNeverSettled(): void
    {
        $this->limitedAfterToolRounds();

        $calls = array_values($this->run->usagePhasesByCall());
        self::assertNotSame([], $calls);

        foreach ($calls as $phases) {
            self::assertSame(['started', 'unsettled'], $phases);
        }

        self::assertSame([self::TOOL_ROUNDS, self::TOOL_ROUNDS], $this->usageRowsOfEndingEvents());
    }

    public function testALongRunThatAnswersAfterManyToolRoundsEndsWithTheVerdictAndSettlesItsCallOnce(): void
    {
        $tools = $this->run->askingForTools();
        $own = new InFlightTogether($this->run->deliverNext(...), ...[...array_fill(0, self::TOOL_ROUNDS, $tools), $this->run->answering('approve')]);

        $review = $this->run->review(1, $this->run->vendor($own, $this->run->answering('approve')));

        self::assertSame(Decision::Approve, $review->decision);
        self::assertSame([], [...$review->reasons]);
        self::assertSame([['started', 'settled']], array_values($this->run->usagePhasesByCall()));
        self::assertSame([self::TOOL_ROUNDS + 1], $this->usageRowsOfEndingEvents());
    }

    /**
     * @return list<int>
     */
    private function usageRowsOfEndingEvents(): array
    {
        $phases = $this->run->payloadValues(LedgerEventKind::UsageRecorded, 'phase');
        $usages = $this->run->payloadValues(LedgerEventKind::UsageRecorded, 'usages');
        $rows = [];

        foreach ($phases as $index => $phase) {
            if ('started' !== $phase) {
                $rows[] = count((array) $usages[$index]);
            }
        }

        return $rows;
    }

    private function stillAskingForTools(): ChangeReview
    {
        $own = new InFlightTogether($this->run->deliverNext(...), $this->run->askingForTools());

        return $this->run->review(1, $this->run->vendor($own, $this->run->answering('approve')));
    }

    private function limitedAfterToolRounds(): ChangeReview
    {
        return $this->run->review(1, $this->run->vendor($this->limitedAfterTools(), $this->limitedAfterTools()));
    }

    // The n-th call goes to the n-th platform, so the limit arrives after a fixed number of tool rounds and not at a moment in time.
    private function limitedAfterTools(): PlatformInterface
    {
        $limit = new FailingWhenRead(new RateLimitExceededException(null, 'Quota exceeded.'));

        return new InFlightTogether($this->run->deliverNext(...), ...[...array_fill(0, self::TOOL_ROUNDS, $this->run->askingForTools()), $limit]);
    }
}
