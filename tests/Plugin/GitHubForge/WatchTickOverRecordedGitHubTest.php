<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineInstallWideFailures;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\UseCase\PushBranch\PushRecords;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Tests\Plugin\Forge\RecordingStatusLabels;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StatusMirrors;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootGate;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootResults;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangesHandedToIntakt;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\DiscardedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\FailedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadBackOff;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadDecisions;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadLocks;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadsWithoutRound;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\LookAtHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\RoundStarts;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\SettleHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\SettleHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StaleVerdicts;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StartedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProjects;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequests;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequestsHandler;
use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\ChangeAuthorship;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeLabels;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\Forge\Contract\Opening;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubChangeLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCiRuns;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubLabelWrites;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubWatchReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\OutvotedLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustedPullRequestLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests\RecordingCommandBus;
use Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests\StandingQueryBus;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;

// One tick over recorded GitHub answers for two pull requests, because the ledger of a real tick is the evidence: #7 with CI still running, #8 with CI finished and green.
#[CoversNothing]
#[Group('persistence')]
final class WatchTickOverRecordedGitHubTest extends KernelTestCase
{
    private const string GREEN = '2222222222222222222222222222222222222222';

    private const string GREEN_PULL = 'https://github.com/acme/widgets/pull/8';

    private EntityManagerInterface $entities;

    private string $firstHeadRuns = 'runs_in_progress.json';

    private ?ChangeLabels $steering = null;

    private ?ReviewStatus $storedStatus = ReviewStatus::InReview;

    private RecordingStatusLabels $statusLabels;

    // Evidence E1 of #3878 over a recorded tick, because a build has no forge access, so this stands in for the live pull request the owner checks after merge.
    public function testAPullRequestsTrustedSteeringLabelsReachTheQueuedRoundWithTheValueAppliedLast(): void
    {
        $pull = new RecordedPullRequestLabels('/repos/acme/widgets/issues/8', RecordedTrust::OWN_IDENTITY);
        $pull->applied('review:full', RecordedTrust::WRITER);
        $pull->applied('model:light', RecordedTrust::WRITER);
        $pull->applied('model:strong', RecordedTrust::WRITER);
        $pull->applied('model:standard', RecordedTrust::TRIAGE);
        $this->steering = $this->changeLabelsOver($pull);
        $commands = new RecordingCommandBus();

        $this->handler($commands)(new WatchPullRequests('alpha'));

        $stored = $this->entities->getRepository(StartedRound::class)->findOneBy(['change' => self::GREEN_PULL]);
        self::assertInstanceOf(StartedRound::class, $stored);
        self::assertSame(
            [['review:full', 'model:strong'], ['review:full', 'model:strong'], ['review:full', 'model:strong', 'model:standard'], ['Two values of one label family were set on this change. Only the one applied last counts, so Intakt removed: `model:light`. If you applied one of them again just now, apply it once more: the value applied last counts.']],
            [[...$commands->started[0]->start->labels], [...$stored->labels()], $pull->labels, $pull->comments],
        );
    }

    // E1 of #3901 over recorded reads: the labels before a round, after the queueing and once the stored round is published; the publication handler itself runs in PublishVerdictTest.
    public function testTheLabelsGoFromNoneToInReviewAfterQueueingAndToWaitingOnYouOncePublished(): void
    {
        self::assertSame([], $this->statusLabels->labels);

        $this->handler(new RecordingCommandBus())(new WatchPullRequests('alpha'));
        $queued = $this->statusLabels->labels;
        $this->storedStatus = ReviewStatus::WaitingOnYou;
        $this->handler(new RecordingCommandBus())(new WatchPullRequests('alpha'));

        self::assertSame([['status:in-review'], ['status:waiting-on-you']], [$queued, $this->statusLabels->labels]);
    }

    public function testATickOverTwoRecordedPullRequestsRecordsOnePendingHeadAndQueuesOneRound(): void
    {
        $commands = new RecordingCommandBus();

        $this->handler($commands)(new WatchPullRequests('alpha'));

        self::assertCount(1, $commands->started);
        self::assertSame(self::GREEN, $commands->started[0]->head->hash);
        $events = $this->recordedEvents();
        self::assertSame(1, preg_match_all('/ head_pending \{[^}]*pull\\\\\/7/', $events));
        self::assertSame(1, preg_match_all('/ round_queued \{[^}]*pull\\\\\/8/', $events));
        self::assertSame(2, preg_match_all('/ change_not_handed_to_intakt /', $events));
        self::assertStringContainsString('4 events', $events);
    }

    public function testATickQueuesTheRoundOfAHeadWhoseQueuedRunANewerGreenRunOfTheSameOriginReplaced(): void
    {
        $this->firstHeadRuns = 'runs_queued_and_newer_green.json';
        $commands = new RecordingCommandBus();

        $this->handler($commands)(new WatchPullRequests('alpha'));

        self::assertCount(2, $commands->started);
        $events = $this->recordedEvents();
        self::assertSame(0, preg_match_all('/ head_pending /', $events));
        self::assertSame(1, preg_match_all('/ round_queued \{[^}]*pull\\\\\/7/', $events));
    }

    // The repository is configured in another case than GitHub lists it, because the push builds its address from the configuration and the listing takes GitHub's.
    public function testATickRecordsThePullRequestIntaktCreatedAsHandedToIntaktAndNotTheOneItOnlyUpdated(): void
    {
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        $records = new PushRecords($ledger);
        $github = new ScriptedGitHub();
        $github->createdNumber = 7;
        $opener = $github->opener('alpha', 'Acme/Widgets');
        $records->open($this->pushOf('feature/7', '1'), str_repeat('1', 40), $opener->openOrUpdate(new ProposedChange('alpha', 'feature/7', 'main', new ChangeText('Seven', 'E1'))));
        // Seeded as an older line, because the opener now refuses the loop's #8 instead of updating it, and the ledger still holds such lines from before.
        $records->open($this->pushOf('feature/8', '2'), self::GREEN, new OpenedChange(new ChangeId('https://github.com/Acme/Widgets/pull/8'), Opening::Updated));

        $this->handler(new RecordingCommandBus())(new WatchPullRequests('alpha'));

        $events = $this->recordedEvents();
        self::assertSame(['POST'], array_map(static fn (array $write): string => explode(' ', $write[0])[0], $github->writes));
        self::assertSame(1, preg_match_all('/ change_handed_to_intakt /', $events));
        self::assertSame(1, preg_match_all('/ change_handed_to_intakt \{[^}]*pull\\\\\/7/', $events));
    }

    // No record of Intakt for #8, because the loop's own pull requests have none and only the author GitHub sets can tell them apart.
    public function testATickRecordsThePullRequestTheAppOpenedAsHandedToIntaktAndTheLoopsOwnAsNotHandedToIntakt(): void
    {
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        $github = new ScriptedGitHub();
        $github->createdNumber = 7;
        new PushRecords($ledger)->open($this->pushOf('feature/7', '1'), str_repeat('1', 40), $github->opener('alpha', 'acme/widgets')->openOrUpdate(new ProposedChange('alpha', 'feature/7', 'main', new ChangeText('Seven', 'E1'))));

        $this->handler(new RecordingCommandBus())(new WatchPullRequests('alpha'));

        $events = $this->recordedEvents();
        self::assertSame(1, preg_match_all('/ change_handed_to_intakt \{[^}]*pull\\\\\/7/', $events));
        self::assertSame(0, preg_match_all('/ change_handed_to_intakt \{[^}]*pull\\\\\/8/', $events));
        self::assertSame(1, preg_match_all('/ change_not_handed_to_intakt \{[^}]*pull\\\\\/8/', $events));
        self::assertSame(0, preg_match_all('/ change_not_handed_to_intakt \{[^}]*pull\\\\\/7/', $events));
    }

    // The first push stops after GitHub created #7 and before `change_opened`, so only the retry writes the record.
    public function testATickRecordsAPullRequestTheAppCreatedBeforeAnInterruptedPushAsHandedToIntakt(): void
    {
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        $records = new PushRecords($ledger);
        $github = new ScriptedGitHub();
        $github->createdNumber = 7;
        $opener = $github->opener('alpha', 'acme/widgets');
        $proposal = new ProposedChange('alpha', 'feature/7', 'main', new ChangeText('Seven', 'E1'));
        $push = $this->pushOf('feature/7', '1');
        $records->intendChange($push, str_repeat('1', 40));
        $opener->openOrUpdate($proposal);
        $github->openPulls = [['number' => 7, 'head' => ['ref' => 'feature/7'], 'base' => ['ref' => 'main'], 'user' => ['login' => 'intakt[bot]', 'type' => 'Bot']]];
        $records->intendChange($push, str_repeat('1', 40));
        $records->open($push, str_repeat('1', 40), $opener->openOrUpdate($proposal));

        $this->handler(new RecordingCommandBus())(new WatchPullRequests('alpha'));

        $events = $this->recordedEvents();
        self::assertSame(['POST', 'PATCH'], array_map(static fn (array $write): string => explode(' ', $write[0])[0], $github->writes));
        self::assertSame(1, preg_match_all('/ change_handed_to_intakt \{[^}]*pull\\\\\/7/', $events));
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::rowsIn(self::getContainer());
        $this->statusLabels = new RecordingStatusLabels([]);
    }

    // The real read of the labels over the recorded pull request, so the permission answer and the family rule decide what reaches the round; the permissions are the ones recorded for `acme/widget`, because the listing's fixtures name `acme/widgets`.
    private function changeLabelsOver(RecordedPullRequestLabels $pull): GitHubChangeLabels
    {
        $identity = new RecordedTrust(TrustChecks::clock())->client();
        $http = new MockHttpClient(static fn (string $method, string $url, array $options): ResponseInterface => $pull->answers((string) parse_url($url, PHP_URL_PATH))
            ? $pull->answer($method, (string) parse_url($url, PHP_URL_PATH), is_string($options['body'] ?? null) ? $options['body'] : '')
            : $identity->request($method, str_replace('/repos/acme/widgets/', '/repos/acme/widget/', $url), $options), 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);
        $repositories = new WatchedRepositories(['alpha' => ['github' => 'acme/widgets']]);
        $outvoted = new OutvotedLabels(new GitHubLabelWrites($wiring->calls, $wiring->tokens), new GitHubIssueComments($wiring->calls, $wiring->tokens, $repositories, '12345'), new NullLogger());

        return new GitHubChangeLabels(new TrustedPullRequestLabels($pull, $wiring->check, $repositories), $outvoted, new NullLogger());
    }

    private function pushOf(string $branch, string $head): BranchPushRequest
    {
        return new BranchPushRequest('alpha', 'repo', 'origin', $branch, new AcceptedHandBack(null, str_repeat($head, 40), 0), new ChangeText('Title', 'E1'));
    }

    private function handler(RecordingCommandBus $commands): WatchPullRequestsHandler
    {
        $clock = new MockClock('2026-11-02T10:00:00+00:00');
        $locks = new LockFactory(new InMemoryStore());
        $logger = new RecordingLogger();
        $ledgerStore = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledgerStore);
        $ledger = new WatcherLedger($ledgerStore);
        $pending = new PendingHeads($this->entities, $clock);
        $rounds = new StartedRounds($this->entities, $pending, new DiscardedRounds($commands, $logger));
        $reads = $this->reads($clock);
        $find = new FindChangedPullRequests($reads, new SeenListings($this->entities, $locks), $logger);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnArgument(0);
        $installWide = new DoctrineInstallWideFailures($this->entities, $doctrine, new DoctrineHolds($this->entities, $clock), $clock, $logger);
        $backOff = new HeadBackOff(new FailedHeads($pending, $ledger, $clock, $redactor, $this->entities->getConnection(), $installWide, $logger, $doctrine), $pending, $doctrine, $logger);
        $decisions = new HeadDecisions(new AdmitHeads($reads, new AdmissionDecision(), $clock, $logger), new AdmissionDecision(), $backOff);
        $steering = $this->steering;
        if (!$steering instanceof ChangeLabels) {
            $steering = self::createStub(ChangeLabels::class);
            $steering->method('steeringOf')->willReturn(new Labels());
        }
        $stored = self::createStub(LatestRoundStatus::class);
        $stored->method('of')->willReturnCallback(fn (): ?ReviewStatus => $this->storedStatus);
        $mirror = new RoundStatusMirror($stored, $locks, $logger, $this->statusLabels);
        $starts = new RoundStarts($commands, $rounds, $ledger, $steering, $mirror);
        $recorded = new VerdictIdentity(new CommitRange(new CommitId(str_repeat('f', 40)), new CommitId(self::GREEN)), ReviewTerms::of('Review it.', 'bin/check', $this->panel()));
        $queries = new StandingQueryBus($recorded, $this->entities);
        // Main's boot line goes to a ledger of its own, because the test counts only the lines of the two heads.
        $authors = self::createStub(AuthorTrust::class);
        $authors->method('isAuthorTrusted')->willReturn(true);
        $gate = new BootGate($queries, $authors, new BootResults($queries, $this->entities, $clock, new WatcherLedger(new InMemoryLedger()), $redactor, $logger, $doctrine, new LockFactory(new InMemoryStore())), $ledger, $logger);
        $settle = new SettleHeads(new SettleHead($gate, new StaleVerdicts($queries, $commands, $rounds, $ledger), new HeadsWithoutRound($ledger, $pending), $starts), $starts, $backOff);
        $projects = new WatchedProjects(['alpha' => ['repository' => 'repo', 'base' => 'main', 'task' => 'Review it.', 'github' => 'acme/widgets']]);

        return new WatchPullRequestsHandler($projects, new ChangedHeads($find, $pending, $ledger, $this->entities->getConnection(), new ChangesHandedToIntakt($ledgerStore, $ledger, self::createStub(ChangeAuthorship::class), $logger, $commands)), new LookAtHeads(new HeadLocks($pending, $locks, $installWide, $logger), $decisions, $settle), new StatusMirrors($find, $rounds, $mirror));
    }

    private function recordedEvents(): string
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function reads(MockClock $clock): GitHubWatchReads
    {
        $http = new MockHttpClient(fn (string $method, string $url): ResponseInterface => $this->answer($url), 'https://api.github.com');
        $identity = new RecordedInstallation($clock, 'installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        return new GitHubWatchReads(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new WatchedRepositories(['alpha' => ['github' => 'acme/widgets']]), new GitHubCiRuns(new GitHubMoments(), $clock), new GitHubMoments(), FreshReadings::reserveOf($readings, $tokens, $clock));
    }

    private function answer(string $url): MockResponse
    {
        return match (true) {
            str_contains($url, '/pulls') => new MockResponse($this->fixture('pulls_open.json'), ['response_headers' => ['ETag' => 'W/"recorded"']]),
            str_contains($url, 'head_sha='.self::GREEN) => new MockResponse($this->fixture('runs_green_head_2.json')),
            str_contains($url, '/actions/runs') => new MockResponse($this->fixture($this->firstHeadRuns)),
            default => new MockResponse($this->fixture('suites_none.json')),
        };
    }

    private function panel(): Panel
    {
        return new Panel([new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10)], QuorumPolicy::All);
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }
}
