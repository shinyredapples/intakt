<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ReviewerStopping::class)]
#[CoversClass(StopRequests::class)]
#[CoversClass(LedgerPayloads::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewerStopped::class)]
final class ReviewerStoppingTest extends TestCase
{
    private DashboardLedger $ledger;

    private UuidV7 $round;

    private UuidV7 $reviewer;

    /** @var list<string> */
    private array $reached = [];

    public function testAPendingStopRequestCancelsTheRunAtItsFirstProgressUpdate(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);

        $status = $this->statusAfterRunning($this->conversation(new UuidV7()));

        self::assertSame(ExecutionStatus::Cancelled, $status);
        self::assertSame(['model_request'], $this->reached);
    }

    public function testARunWithoutAStopRequestFinishes(): void
    {
        $status = $this->statusAfterRunning($this->conversation(new UuidV7()));

        self::assertSame(ExecutionStatus::Completed, $status);
        self::assertSame(['model_request', 'tool_call'], $this->reached);
    }

    // The conversation id differs from the reviewer id, as in production, so matching on the conversation would let the run finish.
    public function testTheRequestIsMatchedOnTheReviewerIdTheLedgerRecordsNotOnTheConversationId(): void
    {
        $conversation = new UuidV7();
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);

        $status = $this->statusAfterRunning($this->conversation($conversation));

        self::assertNotEquals($conversation, $this->reviewer);
        self::assertSame(ExecutionStatus::Cancelled, $status);
    }

    public function testARequestNamingTheConversationIdDoesNotStopTheReviewer(): void
    {
        $conversation = new UuidV7();
        $this->record(LedgerEventKind::ReviewerStopRequested, $conversation);

        self::assertSame(ExecutionStatus::Completed, $this->statusAfterRunning($this->conversation($conversation)));
    }

    public function testARequestForAnotherReviewerOfTheRoundDoesNotStopThisOne(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, new UuidV7());

        self::assertSame(ExecutionStatus::Completed, $this->statusAfterRunning($this->conversation(new UuidV7())));
    }

    // Both rows carry the same recorded time in this ledger, so only their place says which came first.
    public function testARequestAnsweredByAReviewerStoppedRowDoesNotStopALaterRun(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);
        $this->record(LedgerEventKind::ReviewerStopped, $this->reviewer);

        self::assertSame(ExecutionStatus::Completed, $this->statusAfterRunning($this->conversation(new UuidV7())));
    }

    public function testRepeatedRequestsAnsweredByOneStopDoNotStopALaterRun(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);
        $this->record(LedgerEventKind::ReviewerStopped, $this->reviewer);

        self::assertSame(ExecutionStatus::Completed, $this->statusAfterRunning($this->conversation(new UuidV7())));
    }

    public function testARequestRecordedAfterTheLastStopStopsTheRun(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);
        $this->record(LedgerEventKind::ReviewerStopped, $this->reviewer);
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);

        self::assertSame(ExecutionStatus::Cancelled, $this->statusAfterRunning($this->conversation(new UuidV7())));
    }

    public function testAReviewerStoppedRowThatIsNotJsonIsSkippedSoTheRequestStillStopsTheRun(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStopped, '{"round": "'.$this->round->toRfc4122().'"'))->succeeded());

        self::assertSame(ExecutionStatus::Cancelled, $this->statusAfterRunning($this->conversation(new UuidV7())));
    }

    public function testAReviewerStoppedRowOfAnUnknownTierIsSkippedSoTheRequestStillStopsTheRun(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);
        $stopped = str_replace('"strong"', '"giant"', $this->payloadOf(LedgerEventKind::ReviewerStopped, $this->reviewer));
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStopped, $stopped))->succeeded());

        self::assertSame(ExecutionStatus::Cancelled, $this->statusAfterRunning($this->conversation(new UuidV7())));
    }

    public function testAReviewerOutsideARoundIsNeverStopped(): void
    {
        $this->record(LedgerEventKind::ReviewerStopRequested, $this->reviewer);

        self::assertSame(ExecutionStatus::Completed, $this->statusAfterRunning(new ReviewerConversation('You review.', new UuidV7(), reviewerId: $this->reviewer)));
    }

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
        $this->round = new UuidV7();
        $this->reviewer = new UuidV7();
    }

    private function record(LedgerEventKind $kind, Uuid $reviewer): void
    {
        $appended = $this->ledger->append(new LedgerEvent($kind, $this->payloadOf($kind, $reviewer)));
        self::assertTrue($appended->succeeded());
    }

    private function conversation(UuidV7 $conversation): ReviewerConversation
    {
        return new ReviewerConversation('You review.', $conversation, $this->round, $this->reviewer);
    }

    // A stop row carries role and tier, because RoundLedger writes it so and a row without them no longer reads back.
    private function payloadOf(LedgerEventKind $kind, Uuid $reviewer): string
    {
        $pair = ['round' => $this->round->toRfc4122(), 'reviewer' => $reviewer->toRfc4122()];

        return json_encode(LedgerEventKind::ReviewerStopped === $kind ? $pair + ['role' => 'security', 'tier' => 'strong'] : $pair, JSON_THROW_ON_ERROR);
    }

    // Drives the run as the verdict path does, through its updates, and keeps the stages that reached the agent's loop.
    private function statusAfterRunning(ReviewerConversation $reviewer): ExecutionStatus
    {
        $execution = new ReviewerStopping(new StopRequests($this->ledger, new LedgerPayloads(StoredSerializerFactory::create())))->watching(new Execution(function (): Generator {
            $this->reached[] = Progress::STAGE_MODEL_REQUEST;
            yield new Progress(Progress::STAGE_MODEL_REQUEST, 'Invoking model.', 'reviewer-model');
            $this->reached[] = Progress::STAGE_TOOL_CALL;
            yield new Progress(Progress::STAGE_TOOL_CALL, 'Executing tool "read_file".');
            yield new Result(new TextResult('done'));
        }), $reviewer);

        self::assertNotSame([], iterator_to_array($execution, false));

        return $execution->getStatus();
    }
}
