<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgingFailed;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\JudgeRound\QueueJudgingOfCompletedRound;
use Intakt\Core\Review\UseCase\JudgeRound\RecordFailedJudging;
use Intakt\Core\Review\UseCase\JudgeRound\RecordFailedJudgingHandler;
use Intakt\Core\Review\UseCase\JudgeRound\StampFailureOfEarlierJudging;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\FailingBus;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\DispatchOnFailureStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Throwable;

// On the application's Doctrine transports and event dispatcher, so a failure goes through Messenger's own DispatchOnFailureListener and a queued publication outlives a worker restart in the queue table.
#[Group('persistence')]
#[CoversClass(QueueJudgingOfCompletedRound::class)]
#[CoversClass(RecordFailedJudging::class)]
#[CoversClass(RecordFailedJudgingHandler::class)]
#[CoversClass(FailedJudgings::class)]
#[CoversClass(JudgingFailed::class)]
#[CoversClass(StampFailureOfEarlierJudging::class)]
#[CoversClass(JudgedRounds::class)]
#[UsesClass(JudgeRound::class)]
#[UsesClass(JudgeRoundHandler::class)]
#[UsesClass(PublishVerdict::class)]
final class JudgingPublicationTest extends KernelTestCase
{
    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';
    private const string FAILED_FOR_GOOD = 'The judging of round %s failed for good; the round stays unjudged and is published as not reviewed.';
    private const string REMOVED_AFTER_RETRIES = 'Error thrown while handling message %s. Removing from transport after 2 retries. Error: "Handling "%s" failed: %s"';

    private ?string $configuredDsn;
    private RoundFixture $fixture;

    public function testAJudgingThatFailsForGoodRecordsTheFailureAndQueuesThePublicationOnce(): void
    {
        $roundId = $this->completedRound();

        $this->failForGood($roundId);

        self::assertSame([$roundId], $this->publications());
        self::assertSame([$roundId], $this->recordedFailures());
        $this->assertFailedForGoodLogged($roundId);
    }

    public function testAJudgingThatFailsForGoodIsNotParkedInTheFailureTransport(): void
    {
        $roundId = $this->completedRound();

        $this->failForGood($roundId);

        self::assertSame(0, $this->transport('messenger.transport.failed')->getMessageCount());
        $this->assertFailedForGoodLogged($roundId);
    }

    public function testAJudgingThatWillStillBeRetriedRecordsAndPublishesNothing(): void
    {
        $roundId = $this->completedRound();

        $this->reportFailure($this->judging($roundId)->with(new RedeliveryStamp(0)), $this->failureOf($roundId));

        self::assertSame([], $this->publications());
        self::assertSame([], $this->recordedFailures());
    }

    // The worker stopped after the failure message was handled and before it removed the judging, so the judging comes back and fails for good once more.
    public function testAJudgingThatFailsForGoodAgainAfterAWorkerRestartPublishesNothingMore(): void
    {
        $roundId = $this->completedRound();
        $this->failForGood($roundId);

        self::ensureKernelShutdown();
        $this->giveUp($roundId, new RuntimeException('The judge is still down.'));

        self::assertSame([$roundId], $this->publications());
        self::assertSame([$roundId], $this->recordedFailures());
        $this->assertFailedForGoodLogged($roundId);
    }

    public function testAJudgingThatSucceedsQueuesThePublicationOnce(): void
    {
        $roundId = $this->completedRound();

        $this->fixture->judge($this->panel())($this->judgingOf($roundId));

        self::assertSame([$roundId], $this->publications());
        self::assertSame([], $this->recordedFailures());
    }

    // The worker stopped after the judging committed and before it removed the judging, so a fresh worker gets the judging again.
    public function testAWorkerThatRestartsAfterASuccessfulJudgingFindsItsPublicationQueuedOnce(): void
    {
        $roundId = $this->completedRound();
        $this->fixture->judge($this->panel())($this->judgingOf($roundId));

        self::ensureKernelShutdown();
        self::getContainer()->get(JudgeRoundHandler::class)($this->judgingOf($roundId));

        self::assertSame([$roundId], $this->publications());
    }

    public function testAJudgingThatFailsForGoodAfterAnotherWorkerJudgedTheRoundRecordsAndPublishesNothingMore(): void
    {
        $roundId = $this->completedRound();
        $this->fixture->judge($this->panel())($this->judgingOf($roundId));

        $this->giveUp($roundId, new RuntimeException('The judge is down.'));

        self::assertSame([$roundId], $this->publications());
        self::assertSame([], $this->recordedFailures());
    }

    public function testAFailedJudgingWhosePublicationCannotBeQueuedIsNotRecorded(): void
    {
        $roundId = $this->completedRound();

        try {
            $this->fixture->failedJudgingRecorder(new FailingBus(), $this->fixture->log)(new RecordFailedJudging($roundId));
            self::fail('The publication was queued.');
        } catch (RuntimeException) {
            self::assertSame([], $this->recordedFailures());
        }
    }

    public function testAFailedJudgingWhoseLogCannotBeWrittenKeepsItsRecordAndItsQueuedPublication(): void
    {
        $roundId = $this->completedRound();
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('The log is full.'));

        try {
            $this->fixture->failedJudgingRecorder($this->commands(), $logger)(new RecordFailedJudging($roundId));
            self::fail('The log was written.');
        } catch (RuntimeException) {
            self::assertSame([[$roundId], [$roundId]], [$this->publications(), $this->recordedFailures()]);
        }
    }

    // Judged straight after the record, because that is the order a worker stopping before it removes the judging leaves behind when the judge answers on redelivery.
    public function testAJudgingThatSucceedsAfterItsFailureWasRecordedQueuesNoSecondPublication(): void
    {
        $roundId = $this->completedRound();
        $this->failForGood($roundId);

        $this->fixture->judge($this->panel())($this->judgingOf($roundId));

        self::assertSame([$roundId], $this->publications());
        $this->assertFailedForGoodLogged($roundId);
    }

    // Recorded while the judge answers, because that is the order a final failure on another worker leaves behind while this one waits for the model.
    public function testAJudgingThatLosesToARecordedFailureWhileTheJudgeAnswersSaysNothingOfAJudgedRound(): void
    {
        $roundId = $this->completedRound();
        $judged = [];
        $this->fixture->events->addListener(RoundJudged::class, static function (RoundJudged $event) use (&$judged): void {
            $judged[] = $event->roundId;
        });
        $judge = self::createStub(Vendor::class);
        $judge->method('judge')->willReturnCallback(function () use ($roundId): Judgement {
            $this->fixture->failedJudgingRecorder($this->commands(), $this->fixture->log)(new RecordFailedJudging($roundId));

            return new Judgement([]);
        });

        $this->fixture->judge($judge)($this->judgingOf($roundId));

        self::assertSame([[$roundId], [$roundId], []], [$this->publications(), $this->recordedFailures(), $judged]);
        self::assertNotContains(LedgerEventKind::JudgeDone, $this->fixture->ledgerKinds());
    }

    // The worker parked the judging and stopped before it removed the delivery, so the judging comes back and fails for good with its copy parked.
    public function testAParkedCopyOfAJudgingDoesNotKeepItsFailureFromBeingRecordedAndPublished(): void
    {
        $roundId = $this->completedRound();
        $this->transport('messenger.transport.failed')->send(new Envelope(new JudgeRound($roundId)));

        $this->commands()->dispatch(new RecordFailedJudging($roundId));

        self::assertSame([[$roundId], [$roundId]], [$this->publications(), $this->recordedFailures()]);
        $this->assertFailedForGoodLogged($roundId);
    }

    // A round id the recorder cannot read, so recording the failure throws and Messenger parks the judging as it did before the stamp.
    public function testAFailedJudgingWhoseFailureCannotBeRecordedIsParkedAndStillCountsAsFailed(): void
    {
        $roundId = 'not-a-round';
        $judging = new Envelope(new JudgeRound($roundId), [RecordFailedJudging::onFailureOf($roundId)]);

        $this->giveUp($roundId, new RuntimeException('The judge is down.'), $judging);

        self::assertLoggedError('error', sprintf('Failed dispatching %s after message %s failed.', RecordFailedJudging::class, JudgeRound::class));
        self::assertSame(1, $this->transport('messenger.transport.failed')->getMessageCount());
        self::assertTrue(self::getContainer()->get(FailedJudgings::class)->hasFailed($roundId));
    }

    public function testAJudgingQueuedWithoutItsFailureStampGetsItWhenAWorkerReceivesIt(): void
    {
        try {
            $this->commands()->dispatch(new Envelope(new JudgeRound('not-a-round'), [new ReceivedStamp('async')]));
            self::fail('The judging of a missing round was handled.');
        } catch (HandlerFailedException $failed) {
            $failure = $failed->getEnvelope()->last(DispatchOnFailureStamp::class)?->getMessage();

            self::assertEquals(new RecordFailedJudging('not-a-round'), $failure);
        }
    }

    public function testAJudgingQueuedWithoutItsFailureStampThatFailsForGoodQueuesThePublicationOnce(): void
    {
        $roundId = $this->completedRound();
        $unstamped = $this->judging($roundId)->withoutAll(DispatchOnFailureStamp::class);
        $received = new StampFailureOfEarlierJudging()->handle($unstamped, new StackMiddleware());

        $this->giveUp($roundId, $this->failureOf($roundId), $received);

        self::assertSame([[$roundId], [$roundId]], [$this->publications(), $this->recordedFailures()]);
        $this->assertFailedForGoodLogged($roundId);
    }

    public function testAJudgingThatKeepsItsFailureStampKeepsItWhenAWorkerReceivesIt(): void
    {
        $stamp = RecordFailedJudging::onFailureOf('round-a');
        $judging = new Envelope(new JudgeRound('round-b'), [$stamp]);

        $received = new StampFailureOfEarlierJudging()->handle($judging, new StackMiddleware());

        self::assertSame([$stamp], $received->all(DispatchOnFailureStamp::class));
    }

    public function testAMessageOtherThanAJudgingGetsNoFailureStamp(): void
    {
        $received = new StampFailureOfEarlierJudging()->handle(new Envelope(new PublishVerdict('round-a')), new StackMiddleware());

        self::assertNull($received->last(DispatchOnFailureStamp::class));
    }

    protected function setUp(): void
    {
        $configured = $_ENV[self::TRANSPORT_DSN] ?? null;
        $this->configuredDsn = is_string($configured) ? $configured : null;
        $_ENV[self::TRANSPORT_DSN] = 'doctrine://default';

        $this->fixture = new RoundFixture(self::getContainer());
        $this->transport('messenger.transport.async')->setup();
    }

    protected function tearDown(): void
    {
        if (null === $this->configuredDsn) {
            unset($_ENV[self::TRANSPORT_DSN]);
        } else {
            $_ENV[self::TRANSPORT_DSN] = $this->configuredDsn;
        }

        parent::tearDown();
    }

    private function completedRound(): string
    {
        $vendor = $this->panel();
        $roundId = $this->fixture->startOn($this->fixture->bus, 2);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $roundId;
    }

    // A finding, so the judge is asked instead of the round being judged without a call.
    private function panel(): VerdictPerRole
    {
        $finding = new Finding('A', Severity::P1, null, null, null, 'Text of A.', FixableBy::Builder, false, null, null);

        return new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding], [], [])]);
    }

    private function failForGood(string $roundId): void
    {
        $this->giveUp($roundId, $this->failureOf($roundId));
    }

    // The last of the async transport's retries, so Messenger gives the judging up as a worker does, and says so.
    private function giveUp(string $roundId, Throwable $failure, ?Envelope $judging = null): void
    {
        $this->reportFailure(($judging ?? $this->judging($roundId))->with(new RedeliveryStamp(2)), $failure);
        self::assertLoggedError('critical', sprintf(self::REMOVED_AFTER_RETRIES, JudgeRound::class, JudgeRound::class, $failure->getMessage()));
    }

    private function judgingOf(string $roundId): JudgeRound
    {
        $judging = $this->judging($roundId)->getMessage();
        assert($judging instanceof JudgeRound);

        return $judging;
    }

    private function judging(string $roundId): Envelope
    {
        foreach ($this->transport('messenger.transport.async')->all() as $queued) {
            $message = $queued->getMessage();

            if ($message instanceof JudgeRound && $roundId === $message->roundId) {
                return $queued;
            }
        }

        self::fail('The round was not queued for judging.');
    }

    private function failureOf(string $roundId): Throwable
    {
        $failure = new RuntimeException('The judge is down.');

        try {
            $this->fixture->judge(new FailingJudge($failure))($this->judgingOf($roundId));
        } catch (RuntimeException $thrown) {
            return $thrown;
        }

        self::fail('The scripted judge answered.');
    }

    private function reportFailure(Envelope $judging, Throwable $failure): void
    {
        $received = $judging->with(new ReceivedStamp('async'));
        $events = self::getContainer()->get('event_dispatcher');

        $events->dispatch(new WorkerMessageFailedEvent($received, 'async', new HandlerFailedException($received, [JudgeRoundHandler::class => $failure])));
    }

    /**
     * @return list<string>
     */
    private function publications(): array
    {
        $published = [];
        foreach ($this->transport('messenger.transport.async')->all() as $queued) {
            $message = $queued->getMessage();

            if ($message instanceof PublishVerdict) {
                $published[] = $message->roundId;
            }
        }

        return $published;
    }

    // Read through the running container, because a test that restarts the worker leaves the fixture's connection behind.
    /**
     * @return list<string>
     */
    private function recordedFailures(): array
    {
        $payloads = self::getContainer()->get(LedgerPayloads::class);
        $recorded = iterator_to_array(self::getContainer()->get(Ledger::class)->readOfKind(LedgerEventKind::JudgingFailed));

        return array_values(array_map(static fn (RecordedEvent $event): string => $payloads->of($event, JudgingFailed::class)->round ?? '', $recorded));
    }

    private function assertFailedForGoodLogged(string $roundId): void
    {
        self::assertLoggedError('error', sprintf(self::FAILED_FOR_GOOD, $roundId));
    }

    private function commands(): MessageBusInterface
    {
        return self::getContainer()->get('command.bus');
    }

    private function transport(string $id): DoctrineTransport
    {
        $transport = self::getContainer()->get($id);
        assert($transport instanceof DoctrineTransport);

        return $transport;
    }
}
