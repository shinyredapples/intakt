<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Contract\ProtectedHeads;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\PublishVerdict\ChangePublications;
use Intakt\Core\Review\UseCase\PublishVerdict\OwnerAsks;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdictHandler;
use Intakt\Core\Review\UseCase\PublishVerdict\VerdictForTheLoop;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\ReviewerForLoop\Infrastructure\ConfiguredProjectModes;
use Intakt\Core\Watcher\Contract\HandedOverChanges;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StatusMirrors;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubLabelWrites;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusReads;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Evidence E1 of #3901: real stored rounds, the real publication handler and the watcher pass, with the labels read and written over recorded GitHub answers, because a build has no live pull request.
#[CoversNothing]
#[Group('persistence')]
final class StatusLabelOverRecordedGitHubTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string MOMENT = '2026-11-25T10:00:00Z';

    private RoundFixture $fixture;
    private LockFactory $locks;
    private FindChangedPullRequests $listing;

    /** @var list<string> */
    private array $labels = [];

    /** @var list<array<mixed>> */
    private array $events = [];

    /** @var list<string> */
    private array $comments = [];

    private ?Closure $whileReadingLabels = null;

    private ?MockHttpClient $identity = null;

    public function testTheLabelsGoFromNoneToInReviewOnceQueuedAndToWaitingOnYouOnceTheHandlerPublishes(): void
    {
        $this->handOver();
        $this->watcherPass();
        $beforeARound = $this->labels;
        $roundId = $this->fixture->startFor(self::PULL, $this->fixture->bus, 2);
        $this->watcherPass();
        $queued = $this->labels;
        $this->judge();

        $this->publish($roundId);

        self::assertSame([[], ['status:in-review'], ['status:waiting-on-you']], [$beforeARound, $queued, $this->labels]);
        self::assertSame([], $this->comments);
    }

    public function testAJudgedRoundWhosePublicationHasNotRunStaysInReview(): void
    {
        $this->handOver();
        $this->fixture->startFor(self::PULL, $this->fixture->bus, 2);
        $this->judge();

        $this->watcherPass();

        self::assertSame(['status:in-review'], $this->labels);
    }

    // The queue writer's step runs late, after the round was judged, so the publication lands while it holds the lock and reads the labels.
    public function testAPublicationWhileTheQueueWriterHoldsTheLockLeavesOnlyWaitingOnYouAfterTheNextPass(): void
    {
        $this->handOver();
        $roundId = $this->fixture->startFor(self::PULL, $this->fixture->bus, 2);
        $this->judge();
        $this->whileReadingLabels = function () use ($roundId): void {
            $this->whileReadingLabels = null;
            $this->publish($roundId);
        };
        $this->mirror()->reconcile('alpha', new ChangeId(self::PULL));
        self::assertSame(['status:in-review'], $this->labels);
        self::assertNotNull($this->fixture->transactions()->stored($roundId)->publishedAt());

        $this->watcherPass();

        self::assertSame(['status:waiting-on-you'], $this->labels);
        self::assertLessThanOrEqual(1, count($this->comments));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $this->locks = new LockFactory(new InMemoryStore());
        $forge = new RecordedForge();
        $forge->opens('alpha', RecordedForge::change(self::PULL, 'a','2026-11-02T09:00:00Z'));
        $this->listing = new FindChangedPullRequests($forge, new SeenListings($this->fixture->entities, $this->locks), $this->fixture->log);
        self::assertCount(1, ($this->listing)('alpha'));
    }

    private function watcherPass(): void
    {
        new StatusMirrors($this->listing, new StoredLatestRoundStatus($this->fixture->transactions()), $this->mirror(), $this->fixture->log)->reconcileOpenOf('alpha');
    }

    private function mirror(): RoundStatusMirror
    {
        return new RoundStatusMirror(new StoredLatestRoundStatus($this->fixture->transactions()), $this->locks, $this->fixture->log, $this->statusLabels());
    }

    private function judge(): void
    {
        $vendor = new VerdictPerRole([]);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
        foreach ($this->fixture->judgings() as $judging) {
            $this->fixture->judge($vendor)($judging);
        }
    }

    private function publish(string $roundId): void
    {
        $handler = $this->publisher();
        foreach ($this->publicationsOf($roundId) as $publication) {
            $handler($publication);
        }
    }

    private function publisher(): PublishVerdictHandler
    {
        $handed = new HandedOverChanges($this->fixture->ledger());
        $heads = self::createStub(ProtectedHeads::class);
        $heads->method('of')->willReturn(ProtectedChanges::touching(new Paths()));
        $verdicts = new VerdictForTheLoop(new StoredVerdicts(self::getContainer()->get('serializer.stored')), $this->fixture->findingStore(), new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([])), new ChangeDecision());

        return new PublishVerdictHandler($this->fixture->transactions(), new ConfiguredProjectModes([]), $handed, $verdicts, new OwnerAsks($handed, $heads, $this->fixture->ledger()), new ScriptedGitHub()->forge(), new ChangePublications($this->locks), $this->mirror(), $this->fixture->log);
    }

    private function handOver(): void
    {
        self::assertTrue($this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::ChangeHandedToIntakt, json_encode(['project' => 'alpha', 'change' => self::PULL, 'head' => SampleIdentity::HEAD], JSON_THROW_ON_ERROR)))->succeeded());
    }

    /**
     * @return list<PublishVerdict>
     */
    private function publicationsOf(string $roundId): array
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        assert($queue instanceof InMemoryTransport);
        $sent = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $queue->getSent());

        return array_values(array_filter($sent, static fn (object $message): bool => $message instanceof PublishVerdict && $roundId === $message->roundId));
    }

    private function statusLabels(): GitHubStatusLabels
    {
        $this->identity = new RecordedTrust(TrustChecks::clock())->client();
        $wiring = TrustChecks::wiring(new MockHttpClient($this->answer(...), 'https://api.github.com'));
        $repositories = new WatchedRepositories(['alpha' => ['github' => 'acme/widgets']]);
        $comments = new GitHubIssueComments($wiring->calls, $wiring->tokens, $repositories, '12345');

        return new GitHubStatusLabels(new GitHubStatusReads($wiring->calls, $wiring->tokens), $repositories, $wiring->identities, $comments, new GitHubLabelWrites($wiring->calls, $wiring->tokens));
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ('GET' === $method && str_ends_with($path, '/issues/7/labels')) {
            $listed = json_encode(array_map(static fn (string $label): array => ['name' => $label], $this->labels), JSON_THROW_ON_ERROR);
            if ($this->whileReadingLabels instanceof Closure) {
                ($this->whileReadingLabels)();
            }

            return new MockResponse($listed);
        }
        if ('GET' === $method && str_ends_with($path, '/issues/7/events')) {
            return new MockResponse(json_encode($this->events, JSON_THROW_ON_ERROR));
        }
        if (str_contains($path, '/issues/7/')) {
            return $this->write($method, $path, is_string($options['body'] ?? null) ? $options['body'] : '');
        }

        return ($this->identity ?? throw new LogicException('No identity client.'))->request($method, $url, $options);
    }

    // As GitHub does, so a later read sees the label and its event under Intakt's own identity.
    private function write(string $method, string $path, string $body): ResponseInterface
    {
        $payload = '' === $body ? [] : (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (str_ends_with($path, '/comments')) {
            $this->comments[] = is_string($payload['body'] ?? null) ? $payload['body'] : '';
            $stored = [...$payload, 'user' => ['login' => RecordedTrust::OWN_IDENTITY, 'type' => 'Bot'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => 12345]];

            return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
        }
        if ('DELETE' === $method) {
            $this->show(rawurldecode(basename($path)), false);
        }
        foreach ('POST' === $method ? (array) $payload['labels'] : [] as $label) {
            $this->show(is_string($label) ? $label : '', true);
        }

        return new MockResponse('[]');
    }

    private function show(string $label, bool $added): void
    {
        $this->labels = $added ? [...$this->labels, $label] : array_values(array_diff($this->labels, [$label]));
        $this->events[] = ['event' => $added ? 'labeled' : 'unlabeled', 'label' => ['name' => $label], 'actor' => ['login' => RecordedTrust::OWN_IDENTITY], 'created_at' => self::MOMENT];
    }
}
