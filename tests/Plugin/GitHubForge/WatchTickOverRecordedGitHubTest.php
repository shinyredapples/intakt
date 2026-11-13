<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineInstallWideFailures;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootGate;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootResults;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangedHeads;
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
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCiRuns;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubWatchReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
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

    private EntityManagerInterface $entities;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
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
        self::assertStringContainsString('2 events', $events);
    }

    private function recordedEvents(): string
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester->getDisplay();
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
        $starts = new RoundStarts($commands, $rounds, $ledger);
        $recorded = new VerdictIdentity(new CommitRange(new CommitId(str_repeat('f', 40)), new CommitId(self::GREEN)), ReviewTerms::of('Review it.', 'bin/check', $this->panel()));
        $queries = new StandingQueryBus($recorded, $this->entities);
        $settle = new SettleHeads(new SettleHead(new StaleVerdicts($queries, $commands, $rounds, $ledger), new HeadsWithoutRound($ledger, $pending), $starts, new BootGate($queries, new BootResults($queries, $this->entities, $clock, new WatcherLedger(new InMemoryLedger()), $redactor, $logger), $logger)), $starts, $backOff);
        $projects = new WatchedProjects(['alpha' => ['repository' => 'repo', 'base' => 'main', 'task' => 'Review it.', 'github' => 'acme/widgets']]);

        return new WatchPullRequestsHandler($projects, new ChangedHeads($find, $pending, $ledger, $this->entities->getConnection()), new LookAtHeads(new HeadLocks($pending, $locks, $installWide, $logger), $decisions, $settle));
    }

    private function reads(MockClock $clock): GitHubWatchReads
    {
        $http = new MockHttpClient(fn (string $method, string $url): ResponseInterface => $this->answer($url), 'https://api.github.com');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        return new GitHubWatchReads(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new WatchedRepositories(['alpha' => ['github' => 'acme/widgets']]), new GitHubCiRuns(new GitHubMoments()), new GitHubMoments(), FreshReadings::reserveOf($readings, $tokens, $clock));
    }

    private function answer(string $url): MockResponse
    {
        return match (true) {
            str_contains($url, '/pulls') => new MockResponse($this->fixture('pulls_open.json'), ['response_headers' => ['ETag' => 'W/"recorded"']]),
            str_contains($url, 'head_sha='.self::GREEN) => new MockResponse($this->fixture('runs_green_head_2.json')),
            str_contains($url, '/actions/runs') => new MockResponse($this->fixture('runs_in_progress.json')),
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
