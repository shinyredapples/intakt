<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusReconciliation;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubLabelWrites;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusReads;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubStatusLabels::class)]
#[CoversClass(GitHubStatusReads::class)]
#[CoversClass(RoundStatusMirror::class)]
#[UsesClass(StatusReconciliation::class)]
#[UsesClass(ChangeAddress::class)]
#[UsesClass(RoundStatusLabel::class)]
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
    private const string PERSON = RecordedTrust::WRITER;
    private const string MOMENT = '2026-11-25T10:00:00Z';
    private const string ADD_IN_REVIEW = 'POST /repos/acme/widget/issues/1/labels {"labels":["status:in-review"]}';
    private const string ADD_WAITING = 'POST /repos/acme/widget/issues/1/labels {"labels":["status:waiting-on-you"]}';
    private const string REMOVE_IN_REVIEW = 'DELETE /repos/acme/widget/issues/1/labels/status%3Ain-review';

    private ?RoundStatusLabel $round = null;

    /** @var list<string> */
    private array $labels = [];

    /** @var list<array<mixed>> */
    private array $events = [];

    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> */
    private array $comments = [];

    /** @var list<string> */
    private array $skips = [];

    /** @var list<string> */
    private array $statusesAfterTheFailedPass = [];

    private int $labelsStatus = 200;

    private int $eventsStatus = 200;

    private bool $refuseWrites = false;

    private ?Closure $whileReadingLabels = null;

    private ?Closure $whileWriting = null;

    private ?MockHttpClient $identity = null;

    private LockFactory $locks;

    public function testAQueuedRoundAddsTheInReviewLabel(): void
    {
        $this->round = RoundStatusLabel::InReview;

        $this->reconcile();

        self::assertSame([self::ADD_IN_REVIEW], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testAPublishedRoundReplacesInReviewWithWaitingOnYouWithoutAComment(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->reconcile();
        $this->round = RoundStatusLabel::WaitingOnYou;
        $this->writes = [];

        $this->reconcile();

        self::assertSame([self::REMOVE_IN_REVIEW, self::ADD_WAITING], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testAPassThatFindsTheLabelsAsTheRoundStandsWritesAndCommentsNothing(): void
    {
        $this->round = RoundStatusLabel::WaitingOnYou;
        $this->reconcile();
        $this->writes = [];

        $this->reconcile();

        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testOtherLabelsOfTheChangeAreLeftAlone(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->labeled('model:strong', self::PERSON);
        $this->labeled('review:full', self::PERSON);

        $this->reconcile();

        self::assertSame(['model:strong', 'review:full', 'status:in-review'], $this->labels);
    }

    public function testAChangeWithoutARoundKeepsNoStatusLabel(): void
    {
        $this->labeled('status:in-review', self::PERSON);

        $this->reconcile();

        self::assertSame([], $this->statusesNow());
    }

    public function testAPersonWhoAppliedTheStatusTheRoundHasGetsNoCommentWhileTheOtherStatusLabelIsRemoved(): void
    {
        $this->round = RoundStatusLabel::WaitingOnYou;
        $this->labeled('status:in-review', self::OWN);
        $this->labeled('status:waiting-on-you', self::PERSON);

        $this->reconcile();

        self::assertSame([['status:waiting-on-you'], []], [$this->statusesNow(), $this->comments]);
    }

    public function testAStatusLabelAPersonRemovedAfterPublicationIsPutBackWithOneComment(): void
    {
        $this->round = RoundStatusLabel::WaitingOnYou;
        $this->reconcile();
        $this->unlabeled('status:waiting-on-you', self::PERSON);

        $this->reconcile();
        $this->reconcile();

        self::assertSame(['status:waiting-on-you'], $this->statusesNow());
        self::assertCount(1, $this->comments);
    }

    public function testAStatusLabelAPersonChangedAfterPublicationIsPutBackWithOneComment(): void
    {
        $this->round = RoundStatusLabel::WaitingOnYou;
        $this->reconcile();
        $this->unlabeled('status:waiting-on-you', self::PERSON);
        $this->labeled('status:in-review', self::PERSON);

        $this->reconcile();
        $this->reconcile();

        self::assertSame(['status:waiting-on-you'], $this->statusesNow());
        self::assertCount(1, $this->comments);
    }

    public function testAPublicationWhileTheQueueWriterHoldsTheLockIsSkippedAndTheNextPassLeavesWaitingOnYou(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->whileReadingLabels = function (): void {
            $this->whileReadingLabels = null;
            $this->round = RoundStatusLabel::WaitingOnYou;
            $this->reconcile();
        };
        $this->reconcile();

        $this->reconcile();

        self::assertSame([1, ['status:waiting-on-you'], []], [count($this->skips), $this->statusesNow(), $this->comments]);
    }

    public function testTwoOverlappingWritersWriteOnceAndTheNextPassLeavesTheStatusOfTheStoredRound(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->labeled('status:waiting-on-you', self::OWN);
        $this->whileWriting = function (): void {
            $this->whileWriting = null;
            $this->round = RoundStatusLabel::WaitingOnYou;
            $this->reconcile();
        };
        $this->reconcile();

        $this->reconcile();

        self::assertSame([1, ['status:waiting-on-you'], []], [count($this->skips), $this->statusesNow(), $this->comments]);
    }

    public function testAWriterThatOverlapsTheRestorationOfAPersonsChangeLeavesOneComment(): void
    {
        $this->round = RoundStatusLabel::WaitingOnYou;
        $this->reconcile();
        $this->unlabeled('status:waiting-on-you', self::PERSON);
        $this->whileWriting = function (): void {
            $this->whileWriting = null;
            $this->reconcile();
        };
        $this->reconcile();

        $this->reconcile();

        self::assertSame([['status:waiting-on-you'], 1], [$this->statusesNow(), count($this->comments)]);
    }

    public function testAnEventReadThatFailsMidwayWritesNothingAndTheNextPassRestoresWithOneComment(): void
    {
        $this->round = RoundStatusLabel::WaitingOnYou;
        $this->reconcile();
        $this->unlabeled('status:waiting-on-you', self::PERSON);
        $this->labeled('status:in-review', self::PERSON);
        $this->eventsStatus = 503;
        $this->writes = [];
        $this->failedRead();
        $this->eventsStatus = 200;

        $this->reconcile();

        self::assertSame([['status:in-review'], [self::REMOVE_IN_REVIEW, self::ADD_WAITING], ['status:waiting-on-you'], 1], [$this->statusesAfterTheFailedPass, $this->writes, $this->statusesNow(), count($this->comments)]);
    }

    public function testALabelReadThatFailsWritesNothingAndTheNextPassReconciles(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->labelsStatus = 503;
        $this->failedRead();
        $this->labelsStatus = 200;

        $this->reconcile();

        self::assertSame([[], ['status:in-review'], []], [$this->statusesAfterTheFailedPass, $this->statusesNow(), $this->comments]);
    }

    public function testAStatusOfAPullRequestWhoseAddressSpellsTheRepositoryInOtherCaseIsWritten(): void
    {
        $this->round = RoundStatusLabel::InReview;

        $this->matching('https://github.com/Acme/Widget/pull/1');

        self::assertSame(['POST /repos/Acme/Widget/issues/1/labels {"labels":["status:in-review"]}'], $this->writes);
    }

    public function testAChangeOfAnotherRepositoryIsRefused(): void
    {
        $this->expectException(ChangeNotFound::class);

        $this->matching('https://github.com/acme/gadget/pull/1');
    }

    public function testALabelWithoutANameFailsTheRead(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->labels[] = '';

        $this->expectException(ForgeReadFailed::class);

        $this->matching();
    }

    public function testALabelEventWithoutAnActorFailsTheRead(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->events[] = ['event' => 'labeled', 'label' => ['name' => 'status:waiting-on-you'], 'actor' => null, 'created_at' => self::MOMENT];

        $this->expectException(ForgeReadFailed::class);

        $this->matching();
    }

    public function testARefusedWriteFails(): void
    {
        $this->round = RoundStatusLabel::InReview;
        $this->refuseWrites = true;

        $this->expectException(ForgeWriteFailed::class);

        $this->matching();
    }

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
    }

    private function reconcile(): void
    {
        new RoundStatusMirror($this->storedRound(), $this->locks, $this->skipLog(), $this->labelsOf())->reconcile('alpha', new ChangeId(self::PULL));
    }

    // Straight to the adapter, because the mirror logs a failure and drops it.
    private function matching(?string $address = null): void
    {
        $this->labelsOf()->match('alpha', new ChangeId($address ?? self::PULL), $this->round);
    }

    private function storedRound(): LatestRoundStatus
    {
        return new readonly class($this->roundNow(...)) implements LatestRoundStatus {
            public function __construct(private Closure $now)
            {
            }

            public function of(string $project, string $change): ?ReviewStatus
            {
                $status = ($this->now)();

                return $status instanceof RoundStatusLabel ? ReviewStatus::from(substr($status->value, strlen(RoundStatusLabel::FAMILY_PREFIX))) : null;
            }
        };
    }

    private function roundNow(): ?RoundStatusLabel
    {
        return $this->round;
    }

    private function skipLog(): AbstractLogger
    {
        return new class($this->skipped(...)) extends AbstractLogger {
            public function __construct(private readonly Closure $skipped)
            {
            }

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                ($this->skipped)((string) $message);
            }
        };
    }

    private function skipped(string $message): void
    {
        $this->skips[] = $message;
    }

    // Records the status labels the failed pass left, so a test can show that it wrote nothing.
    private function failedRead(): void
    {
        try {
            $this->matching();
            self::fail('The read should have failed.');
        } catch (ForgeReadFailed) {
            $this->statusesAfterTheFailedPass = $this->statusesNow();
        }
    }

    private function labeled(string $label, string $actor): void
    {
        $this->labels[] = $label;
        $this->events[] = ['event' => 'labeled', 'label' => ['name' => $label], 'actor' => ['login' => $actor], 'created_at' => self::MOMENT];
    }

    private function unlabeled(string $label, string $actor): void
    {
        $this->labels = array_values(array_diff($this->labels, [$label]));
        $this->events[] = ['event' => 'unlabeled', 'label' => ['name' => $label], 'actor' => ['login' => $actor], 'created_at' => self::MOMENT];
    }

    /**
     * @return list<string>
     */
    private function statusesNow(): array
    {
        return array_values(array_filter($this->labels, static fn (string $label): bool => str_starts_with($label, 'status:')));
    }

    private function labelsOf(): GitHubStatusLabels
    {
        $trust = new RecordedTrust(TrustChecks::clock());
        $this->identity = $trust->client();
        $http = new MockHttpClient($this->answer(...), 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);
        $repositories = new WatchedRepositories(['alpha' => ['github' => 'acme/widget']]);
        $comments = new GitHubIssueComments($wiring->calls, $wiring->tokens, $repositories, '12345');

        return new GitHubStatusLabels(new GitHubStatusReads($wiring->calls, $wiring->tokens), $repositories, $wiring->identities, $comments, new GitHubLabelWrites($wiring->calls, $wiring->tokens));
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ('GET' === $method && 1 === preg_match('#/issues/[0-9]+/labels$#', $path)) {
            return $this->listing($this->labelsStatus, array_map(static fn (string $label): array => ['name' => $label], $this->labels), $this->whileReadingLabels);
        }
        if ('GET' === $method && 1 === preg_match('#/issues/[0-9]+/events$#', $path)) {
            return $this->listing($this->eventsStatus, $this->events, null);
        }
        if (1 === preg_match('#/issues/[0-9]+/#', $path)) {
            return $this->write($method, $path, $options);
        }

        return ($this->identity ?? throw new LogicException('No identity client.'))->request($method, $url, $options);
    }

    /**
     * @param list<array<mixed>> $entries
     */
    private function listing(int $status, array $entries, ?Closure $meanwhile): ResponseInterface
    {
        $listed = json_encode(200 === $status ? $entries : ['message' => 'unavailable'], JSON_THROW_ON_ERROR);
        if ($meanwhile instanceof Closure) {
            $meanwhile();
        }

        return new MockResponse($listed, ['http_code' => $status]);
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
            return $this->comment($body);
        }

        $this->writes[] = trim($method.' '.$path.' '.$body);
        $this->showOnTheChange($method, $path, $body);
        if ($this->whileWriting instanceof Closure) {
            ($this->whileWriting)();
        }

        return new MockResponse('[]', ['http_code' => 200]);
    }

    private function comment(string $body): ResponseInterface
    {
        $payload = (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->comments[] = is_string($payload['body'] ?? null) ? $payload['body'] : '';
        $stored = [...$payload, 'user' => ['login' => self::OWN, 'type' => 'Bot'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => 12345]];

        return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    // As GitHub does, so a later read sees the label and its event under Intakt's own identity.
    private function showOnTheChange(string $method, string $path, string $body): void
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
