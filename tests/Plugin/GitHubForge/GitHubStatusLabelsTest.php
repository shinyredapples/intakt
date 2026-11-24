<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelPlan;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubLabelWrites;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubStatusLabels::class)]
#[UsesClass(StatusLabelPlan::class)]
#[UsesClass(LabelEvent::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(TaskIssue::class)]
#[UsesClass(GitHubIssueComments::class)]
#[UsesClass(GitHubLabelWrites::class)]
final class GitHubStatusLabelsTest extends TestCase
{
    private const string PULL = RecordedTrust::PULL_BY_WRITER;
    private const string OWN = RecordedTrust::OWN_IDENTITY;
    private const string MOMENT = '2026-11-25T10:00:00Z';

    /** @var list<array<mixed>> */
    private array $events = [];

    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> */
    private array $comments = [];

    private int $eventsStatus = 200;

    private bool $refuseWrites = false;

    private ?MockHttpClient $identity = null;

    private ?Closure $whileWriting = null;

    private ?Closure $whileReading = null;

    private bool $writesShowInEvents = false;

    private LockFactory $locks;

    private MockClock $clock;

    public function testAQueuedRoundWritesTheInReviewLabel(): void
    {
        $this->mirror(RoundStatusLabel::InReview);

        self::assertSame(['POST /repos/acme/widget/issues/1/labels {"labels":["status:in-review"]}'], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testARoundWaitingOnTheOwnerReplacesInReviewWithoutAComment(): void
    {
        $this->labeled('status:in-review', self::OWN);

        $this->mirror(RoundStatusLabel::WaitingOnYou);

        self::assertSame(['DELETE /repos/acme/widget/issues/1/labels/status%3Ain-review', 'POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}'], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testAStatusLabelChangedByAPersonIsRestoredWithOneComment(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->labeled('status:waiting-on-you', RecordedTrust::WRITER);

        $this->mirror(RoundStatusLabel::InReview);

        self::assertSame(['DELETE /repos/acme/widget/issues/1/labels/status%3Awaiting-on-you'], $this->writes);
        self::assertCount(1, $this->comments);
    }

    public function testASecondPassAfterTheRestoreWritesAndCommentsNothing(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->labeled('status:waiting-on-you', RecordedTrust::WRITER);
        $this->mirror(RoundStatusLabel::InReview);
        $this->unlabeled('status:waiting-on-you', self::OWN);
        $this->writes = [];
        $this->comments = [];

        $this->mirror(RoundStatusLabel::InReview);

        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testAWriterThatOverlapsAnotherPastTheWaitWritesWithoutCommentingSoThePersonsChangeGetsOneComment(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->labeled('status:waiting-on-you', RecordedTrust::WRITER);
        $this->whileWriting = function (): void {
            $this->whileWriting = null;
            $this->mirror(RoundStatusLabel::InReview);
        };

        $this->mirror(RoundStatusLabel::InReview);

        self::assertCount(1, $this->comments);
    }

    public function testAVerdictStatusThatArrivesWhileARestorationHoldsTheLockPastTheWaitIsStillWritten(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->unlabeled('status:in-review', RecordedTrust::WRITER);
        $this->whileWriting = function (): void {
            $this->whileWriting = null;
            $this->mirror(RoundStatusLabel::WaitingOnYou);
        };

        $this->restore();

        self::assertContains('POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}', $this->writes);
    }

    public function testAVerdictStatusWrittenPastTheWaitWhileARestorationReadsIsNotOverwrittenByTheRestoration(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->unlabeled('status:in-review', RecordedTrust::WRITER);
        $this->writesShowInEvents = true;
        $this->whileReading = function (): void {
            $this->whileReading = null;
            $this->mirror(RoundStatusLabel::WaitingOnYou);
        };

        $this->restore();

        self::assertSame(['POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}'], $this->writes);
    }

    public function testALabelListThatKeepsChangingFailsTheWriteWithoutWriting(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->whileReading = function (): void {
            $this->labeled('status:in-review', RecordedTrust::WRITER);
        };

        try {
            $this->mirror(RoundStatusLabel::WaitingOnYou);
            self::fail('The write should have failed.');
        } catch (ForgeWriteFailed) {
            self::assertSame([], $this->writes);
        }
    }

    public function testAStatusOfAPullRequestWhoseAddressSpellsTheRepositoryInOtherCaseIsWritten(): void
    {
        $this->labelsOf()->mirror('alpha', new ChangeId('https://github.com/Acme/Widget/pull/1'), RoundStatusLabel::InReview);

        self::assertSame(['POST /repos/Acme/Widget/issues/1/labels {"labels":["status:in-review"]}'], $this->writes);
    }

    public function testAVerdictStatusThatArrivesWhileARestorationHoldsTheLockWaitsAndIsWritten(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->locks = new LockFactory(new BusyForAWhileStore(1));

        $this->mirror(RoundStatusLabel::WaitingOnYou);

        self::assertSame(['DELETE /repos/acme/widget/issues/1/labels/status%3Ain-review', 'POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}'], $this->writes);
        self::assertSame('2026-11-25T10:00:00.500', $this->clock->now()->format('Y-m-d\TH:i:s.v'));
    }

    public function testAStatusWhoseLockStaysBusyForTheWholeWaitIsWrittenWithoutTheLock(): void
    {
        $this->locks = new LockFactory(new BusyForAWhileStore(20));

        $this->mirror(RoundStatusLabel::WaitingOnYou);

        self::assertSame(['POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}'], $this->writes);
        self::assertSame('2026-11-25T10:00:09.500', $this->clock->now()->format('Y-m-d\TH:i:s.v'));
    }

    public function testAStatusWrittenWithoutTheLockLeavesTheCommentToTheHolder(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->labeled('status:waiting-on-you', RecordedTrust::WRITER);
        $this->locks = new LockFactory(new BusyForAWhileStore(20));

        $this->mirror(RoundStatusLabel::InReview);

        self::assertSame(['DELETE /repos/acme/widget/issues/1/labels/status%3Awaiting-on-you'], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testAStatusWhoseLockFreesOnTheLastTryOfTheWaitIsWritten(): void
    {
        $this->locks = new LockFactory(new BusyForAWhileStore(19));

        $this->mirror(RoundStatusLabel::WaitingOnYou);

        self::assertSame(['POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}'], $this->writes);
    }

    public function testARestorationThatFindsTheLockBusyLeavesTheLabelToItsHolderWithoutWaiting(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->unlabeled('status:in-review', RecordedTrust::WRITER);
        $store = new BusyForAWhileStore(1);
        $this->locks = new LockFactory($store);

        $this->restore();

        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
        self::assertSame(1, $store->tries);
    }

    public function testAStatusLabelAPersonRemovedAfterTheVerdictIsPutBackWithOneComment(): void
    {
        $this->labeled('status:in-review', self::OWN);
        $this->unlabeled('status:in-review', self::OWN);
        $this->labeled('status:waiting-on-you', self::OWN);
        $this->unlabeled('status:waiting-on-you', RecordedTrust::WRITER);

        $this->restore();

        self::assertSame(['POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}'], $this->writes);
        self::assertCount(1, $this->comments);
    }

    public function testARestoreWhileTheStatusIsAsIntaktSetItWritesAndCommentsNothing(): void
    {
        $this->labeled('status:waiting-on-you', self::OWN);

        $this->restore();

        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testARestoreOnAChangeIntaktNeverLabeledWritesNothing(): void
    {
        $this->labeled('status:waiting-on-you', RecordedTrust::WRITER);

        $this->restore();

        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testAChangeOfAnotherRepositoryIsRefused(): void
    {
        $this->expectException(ChangeNotFound::class);

        $this->labelsOf()->mirror('alpha', new ChangeId('https://github.com/acme/gadget/pull/1'), RoundStatusLabel::InReview);
    }

    public function testAnEventListingGitHubRefusesFailsTheReadBeforeAnyWrite(): void
    {
        $this->eventsStatus = 500;

        try {
            $this->mirror(RoundStatusLabel::InReview);
            self::fail('The read should have failed.');
        } catch (ForgeReadFailed) {
            self::assertSame([], $this->writes);
        }
    }

    public function testALabelEventWithoutAnActorFailsTheRead(): void
    {
        $this->events[] = ['event' => 'labeled', 'label' => ['name' => 'status:in-review'], 'actor' => null, 'created_at' => self::MOMENT];

        $this->expectException(ForgeReadFailed::class);

        $this->mirror(RoundStatusLabel::InReview);
    }

    public function testARefusedWriteFails(): void
    {
        $this->expectException(ForgeWriteFailed::class);

        $this->refuseWrites = true;

        $this->labelsOf()->mirror('alpha', new ChangeId(self::PULL), RoundStatusLabel::InReview);
    }

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $this->clock = new MockClock(self::MOMENT);
    }

    private function mirror(RoundStatusLabel $status): void
    {
        $this->labelsOf()->mirror('alpha', new ChangeId(self::PULL), $status);
    }

    private function restore(): void
    {
        $this->labelsOf()->restore('alpha', new ChangeId(self::PULL));
    }

    private function labeled(string $label, string $actor): void
    {
        $this->events[] = ['event' => 'labeled', 'label' => ['name' => $label], 'actor' => ['login' => $actor], 'created_at' => self::MOMENT];
    }

    private function unlabeled(string $label, string $actor): void
    {
        $this->events[] = ['event' => 'unlabeled', 'label' => ['name' => $label], 'actor' => ['login' => $actor], 'created_at' => self::MOMENT];
    }

    private function labelsOf(): GitHubStatusLabels
    {
        $trust = new RecordedTrust();
        $this->identity = $trust->client();
        $http = new MockHttpClient($this->answer(...), 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);
        $repositories = new WatchedRepositories(['alpha' => ['github' => 'acme/widget']]);
        $comments = new GitHubIssueComments($wiring->calls, $wiring->tokens, $repositories, '12345');

        return new GitHubStatusLabels($wiring->calls, $wiring->tokens, $repositories, $wiring->identities, $comments, new GitHubLabelWrites($wiring->calls, $wiring->tokens), $this->locks, $this->clock);
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_ends_with($path, '/issues/1/events')) {
            $listed = json_encode(200 === $this->eventsStatus ? $this->events : ['message' => 'no'], JSON_THROW_ON_ERROR);
            if ($this->whileReading instanceof Closure) {
                ($this->whileReading)();
            }

            return new MockResponse($listed, ['http_code' => $this->eventsStatus]);
        }
        if (str_contains($path, '/issues/1/')) {
            return $this->write($method, $path, $options);
        }

        return ($this->identity ?? throw new LogicException('No identity client.'))->request($method, $url, $options);
    }

    /**
     * @param array<mixed> $options
     */
    private function write(string $method, string $path, array $options): ResponseInterface
    {
        if ($this->refuseWrites) {
            return new MockResponse('{"message":"Refused"}', ['http_code' => 403]);
        }

        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        if (str_ends_with($path, '/comments')) {
            $payload = (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->comments[] = is_string($payload['body'] ?? null) ? $payload['body'] : '';
            $stored = [...$payload, 'user' => ['login' => self::OWN, 'type' => 'Bot'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => 12345]];

            return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
        }

        $this->writes[] = trim($method.' '.$path.' '.$body);
        if ($this->writesShowInEvents) {
            $this->showInEvents($method, $path, $body);
        }
        if ($this->whileWriting instanceof Closure) {
            ($this->whileWriting)();
        }

        return new MockResponse('[]', ['http_code' => 200]);
    }

    // As GitHub does, so a later read of the events sees what Intakt wrote.
    private function showInEvents(string $method, string $path, string $body): void
    {
        if ('DELETE' === $method) {
            $this->unlabeled(rawurldecode(basename($path)), self::OWN);

            return;
        }

        $labels = (array) ((array) json_decode($body, true, flags: JSON_THROW_ON_ERROR))['labels'];
        foreach ($labels as $label) {
            $this->labeled(is_string($label) ? $label : '', self::OWN);
        }
    }
}
