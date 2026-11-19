<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ProjectStatuses;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusReadModel;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
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
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\ReviewChange\StoredRoundEndings;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeCommand;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\FailingJudge;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
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

    private string $reviewedRound = '';

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

    public function testTheReviewCommandEndsARoundWhoseJudgingFailedForGoodAsBlockedAndTheStatusShowsItFailed(): void
    {
        $console = $this->reviewedByTheCommand();

        $lines = explode("\n", rtrim($console->getDisplay(), "\n"));

        self::assertSame(ReviewExit::Blocked->value, $console->getStatusCode());
        self::assertSame('blocked (round 1 counted)', $lines[0]);
        self::assertContains('reason: '.RoundEndReasons::JUDGING_FAILED, $lines);
        self::assertSame([[$this->reviewedRound, 'judging_failed']], array_map(static fn (array $outcome): array => [$outcome['round'], $outcome['end']], $this->projectInStatus('alpha')['last_outcomes']));
    }

    // On a branch of a temporary repository, so the lines are the command's own output while the judge fails for good during its wait.
    private function reviewedByTheCommand(): CommandTester
    {
        $repository = new TemporaryRepository();
        $repository->commitFile('README.md', "base\n");
        $repository->checkoutNewBranch(RoundFixture::CHANGE);
        $repository->commitFile('src/Thing.php', "<?php\n");
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($this->panel());
        $application = new ConsoleApplication();
        $application->addCommand(new ReviewChangeCommand(new LocalChangeReview($panels, $this->reviewChange()), new ReviewReport(StoredSerializerFactory::create())));
        $tester = new CommandTester($application->find('intakt:review'));

        try {
            $tester->execute(['change' => RoundFixture::CHANGE, '--project' => 'alpha', '--task' => RoundFixture::TASK, '--repository' => $repository->path]);
        } finally {
            $repository->remove();
        }

        return $tester;
    }

    private function reviewChange(): ReviewChange
    {
        $fixture = $this->fixture;
        $vendor = $this->panelAskingForChanges();
        $work = function () use ($fixture, $vendor): void {
            foreach ($fixture->bus->messages as $message) {
                $fixture->deliver($message, $vendor);
            }

            foreach ($this->transport('messenger.transport.async')->all() as $queued) {
                $judging = $queued->getMessage();

                if ($judging instanceof JudgeRound && '' === $this->reviewedRound) {
                    $this->reviewedRound = $judging->roundId;
                    $this->giveUpJudging($judging->roundId);
                }
            }
        };
        $serializer = self::getContainer()->get('serializer.stored');

        return new ReviewChange(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$fixture->startHandlerOn($fixture->bus)]]))]),
            $fixture->transactions(),
            new StoredFindings($fixture->entities, $serializer, $fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($fixture->ledger(), $fixture->ledgerPayloads(), new ConfiguredPrices([])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($fixture->clock, $work),
            new FailureInWords(),
            $vendor,
            $fixture->noFailedJudgings(),
        );
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
