<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ProjectStatuses;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusReadModel;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Core\Review\UseCase\ReviewChange\StoredRoundEndings;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeCommand;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\FailingJudge;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\JudgeCallingVendor;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\PinnableClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\ClockInterface;
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
#[UsesClass(ReviewChange::class)]
#[UsesClass(ReviewChangeCommand::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(ReviewReport::class)]
final class JudgingFailedForGoodStatusTest extends KernelTestCase
{
    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';
    private const string FAILURE = 'The judge is down.';

    private ?string $configuredDsn;
    private RoundFixture $fixture;

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

    public function testTheReviewCommandEndsARoundWhoseJudgingFailedForGoodAsBlockedAndTheStatusShowsItFailed(): void
    {
        $console = $this->reviewedByTheInstalledCommand();

        $lines = explode("\n", rtrim($console->getDisplay(), "\n"));
        $judgingFailures = array_values(array_filter(iterator_to_array(self::getContainer()->get(Ledger::class)->readInOrder()), static fn (RecordedEvent $recorded): bool => LedgerEventKind::JudgingFailed === $recorded->event->kind));

        self::assertSame(ReviewExit::Blocked->value, $console->getStatusCode());
        self::assertSame('blocked (round 1 counted)', $lines[0]);
        self::assertContains('reason: '.RoundEndReasons::JUDGING_FAILED, $lines);
        self::assertCount(1, $judgingFailures);
        /** @var array{round: string} $failure */
        $failure = json_decode($judgingFailures[0]->event->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertLoggedError('error', sprintf('The judging of round %s failed for good; the round stays unjudged and is published as not reviewed.', $failure['round']));
        self::assertSame([[RoundFixture::CHANGE, $failure['round'], 'judging_failed', null]], array_map(static fn (array $outcome): array => [$outcome['change'], $outcome['round'], $outcome['end'], $outcome['decision']], $this->projectInStatus('alpha')['last_outcomes']));
        self::assertSame(0, $this->transport('messenger.transport.failed')->getMessageCount());
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

    // Only the panel and the vendor are the test's, because the proof is the installed wiring of both commands; the worker runs during the command's waits, because on an install a worker process runs beside it.
    private function reviewedByTheInstalledCommand(): CommandTester
    {
        $repository = new TemporaryRepository();
        $repository->commitFile('README.md', "base\n");
        $repository->checkoutNewBranch(RoundFixture::CHANGE);
        $repository->commitFile('src/Thing.php', "<?php\n");
        $container = self::getContainer();
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($this->panel());
        $container->set(Panels::class, $panels);
        $container->set(Vendor::class, new JudgeCallingVendor($this->panelAskingForChanges(), static fn (): never => throw new RuntimeException(self::FAILURE)));
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $console = new Application($kernel);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(PinnableClock::class, $clock);
        $clock->pinAt('2026-11-20T12:00:00+00:00');
        $clock->workOnEachSleep(static function () use ($console): void {
            new CommandTester($console->find('messenger:consume'))->execute(['receivers' => ['async'], '--time-limit' => 1, '--sleep' => 0.1, '--no-reset' => null]);
        });
        $tester = new CommandTester($console->find('intakt:review'));

        try {
            $tester->execute(['change' => RoundFixture::CHANGE, '--project' => 'alpha', '--task' => RoundFixture::TASK, '--repository' => $repository->path]);
        } finally {
            $repository->remove();
        }

        self::assertLoggedError('critical', sprintf('Error thrown while handling message %s. Removing from transport after 2 retries. Error: "Handling "%s" failed: %s"', JudgeRound::class, JudgeRound::class, self::FAILURE));

        return $tester;
    }

    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
    }

    // A finding for the judge to call, because a round whose reviewers all approve is judged without asking the judge.
    private function panelAskingForChanges(): VerdictPerRole
    {
        $finding = new Finding('A', Severity::P1, null, null, null, 'Text of A.', FixableBy::Builder, false, null, null);

        return new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding], [], [])]);
    }

    private function roundWhoseJudgingFailedForGood(): string
    {
        $panel = $this->panelAskingForChanges();
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
