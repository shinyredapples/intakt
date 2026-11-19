<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ProjectStatuses;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusReadModel;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\ReviewChange\StoredRoundEndings;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\FailingJudge;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

// Through the kernel, the Doctrine ledger and transports and Messenger's own failure handling, because the status of a judging that failed for good must come from what a worker leaves behind.
#[Group('persistence')]
#[CoversClass(ProjectStatuses::class)]
#[CoversClass(StoredRoundEndings::class)]
#[UsesClass(StatusReadModel::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(JudgeRound::class)]
#[UsesClass(JudgeRoundHandler::class)]
final class JudgingFailedForGoodStatusTest extends KernelTestCase
{
    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';
    private const string FAILURE = 'The judge is down.';

    private ?string $configuredDsn;
    private RoundFixture $fixture;

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

    public function testStatusShowsARoundWhoseJudgingFailedForGoodAsAJudgingFailedOutcomeAndNoLongerInReview(): void
    {
        $roundId = $this->roundWhoseJudgingFailedForGood();

        $alpha = $this->projectInStatus('alpha');

        self::assertSame([], $alpha['lanes']['review']);
        self::assertSame([[$roundId, 'judging_failed', null]], array_map(static fn (array $outcome): array => [$outcome['round'], $outcome['end'], $outcome['decision']], $alpha['last_outcomes']));
    }

    public function testTheDashboardShowsARoundWhoseJudgingFailedForGoodAsFailedWithNothingParked(): void
    {
        $roundId = $this->roundWhoseJudgingFailedForGood();

        $board = self::getContainer()->get(LedgerReviewPages::class)->boardOf(self::getContainer()->get(Ledger::class)->readInOrder());
        $latest = array_map(static fn (Review $card): array => [$card->latest()->round, $card->latest()->reviewOutcome()], $board->cardsIn(BoardColumn::InReview));

        self::assertSame([[$roundId, ReviewOutcome::Failed]], $latest);
        self::assertSame(0, $this->transport('messenger.transport.failed')->getMessageCount());
    }

    private function roundWhoseJudgingFailedForGood(): string
    {
        $finding = new Finding('A', Severity::P1, null, null, null, 'Text of A.', FixableBy::Builder, false, null, null);
        $panel = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding], [], [])]);
        $roundId = $this->fixture->startOn($this->fixture->bus, 2);

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $panel);
        }

        $this->giveUpJudging($roundId);

        return $roundId;
    }

    // The judge's own failure on the last of the async transport's retries, reported as a worker does, so Messenger dispatches the judging's failure message.
    private function giveUpJudging(string $roundId): void
    {
        $judging = $this->judging($roundId);
        $failure = new RuntimeException(self::FAILURE);
        $message = $judging->getMessage();
        self::assertInstanceOf(JudgeRound::class, $message);

        try {
            $this->fixture->judge(new FailingJudge($failure))($message);
        } catch (RuntimeException $thrown) {
            $failure = $thrown;
        }

        $received = $judging->with(new RedeliveryStamp(2), new ReceivedStamp('async'));
        self::getContainer()->get('event_dispatcher')->dispatch(new WorkerMessageFailedEvent($received, 'async', new HandlerFailedException($received, [JudgeRoundHandler::class => $failure])));

        self::assertLoggedError('critical', sprintf('Error thrown while handling message %s. Removing from transport after 2 retries. Error: "Handling "%s" failed: %s"', JudgeRound::class, JudgeRound::class, self::FAILURE));
        self::assertLoggedError('error', sprintf('The judging of round %s failed for good; the round stays unjudged and is published as not reviewed.', $roundId));
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

    /**
     * @return array{lanes: array{review: list<array<string, mixed>>}, last_outcomes: list<array<string, mixed>>}
     */
    private function projectInStatus(string $name): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $command = new CommandTester(new Application($kernel)->find('intakt:status'));
        $command->execute([]);
        $command->assertCommandIsSuccessful();

        /** @var array{projects: list<array{name: string, lanes: array{review: list<array<string, mixed>>}, last_outcomes: list<array<string, mixed>>}>} $status */
        $status = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $project = array_find($status['projects'], static fn (array $project): bool => $name === $project['name']);
        self::assertNotNull($project);

        return $project;
    }

    private function transport(string $id): DoctrineTransport
    {
        $transport = self::getContainer()->get($id);
        self::assertInstanceOf(DoctrineTransport::class, $transport);

        return $transport;
    }
}
