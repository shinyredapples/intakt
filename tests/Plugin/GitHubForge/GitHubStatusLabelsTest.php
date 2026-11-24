<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelPlan;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubStatusLabels::class)]
#[UsesClass(StatusLabelPlan::class)]
#[UsesClass(LabelEvent::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(TaskIssue::class)]
#[UsesClass(GitHubIssueComments::class)]
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

    private function mirror(RoundStatusLabel $status): void
    {
        $this->labelsOf()->mirror('alpha', new ChangeId(self::PULL), $status);
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

        return new GitHubStatusLabels($wiring->calls, $wiring->tokens, $repositories, $wiring->identities, $comments);
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_ends_with($path, '/issues/1/events')) {
            return new MockResponse(json_encode(200 === $this->eventsStatus ? $this->events : ['message' => 'no'], JSON_THROW_ON_ERROR), ['http_code' => $this->eventsStatus]);
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

        return new MockResponse('[]', ['http_code' => 200]);
    }
}
