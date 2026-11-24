<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Domain\LabelFamilies;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Domain\TrustedLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubChangeLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubLabelWrites;
use Intakt\Plugin\GitHubForge\Infrastructure\OutvotedLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustedPullRequestLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubChangeLabels::class)]
#[CoversClass(OutvotedLabels::class)]
#[UsesClass(TrustedPullRequestLabels::class)]
#[UsesClass(TrustedLabels::class)]
#[UsesClass(LabelFamilies::class)]
#[UsesClass(LabelApplication::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(IssueLabels::class)]
#[UsesClass(GitHubLabelWrites::class)]
#[UsesClass(GitHubIssueComments::class)]
#[UsesClass(TaskIssue::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
final class GitHubChangeLabelsTest extends TestCase
{
    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> */
    private array $comments = [];

    private ?MockHttpClient $identity = null;

    public function testAFailedReadCountsNoSteeringLabel(): void
    {
        $histories = self::createStub(IssueHistories::class);
        $histories->method('of')->willThrowException(new ForgeReadFailed('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $labels = $this->labelsOver($histories, $logger)->steeringOf('alpha', $this->open());

        self::assertSame([], [...$labels]);
        self::assertSame([], $this->writes);
    }

    public function testTheValueAppliedEarlierInAFamilyIsRemovedWithOneComment(): void
    {
        $labels = $this->labelsOver($this->history('model:light', 'model:strong'), new NullLogger())->steeringOf('alpha', $this->open());

        self::assertSame(['model:strong'], [...$labels]);
        self::assertSame(['DELETE /repos/acme/widget/issues/7/labels/model%3Alight'], $this->writes);
        self::assertSame(['Two values of one label family were set on this change. Only the one applied last counts, so Intakt removed: `model:light`.'], $this->comments);
    }

    public function testOneValuePerFamilyRemovesNothing(): void
    {
        $this->labelsOver($this->history('model:strong', 'review:full'), new NullLogger())->steeringOf('alpha', $this->open());

        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testAPullRequestOfAnotherRepositorySteersNothingAndRemovesNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $foreign = new OpenChange(new ChangeId('https://github.com/acme/another/pull/7'), new CommitId(str_repeat('a', 40)), new DateTimeImmutable('2026-11-25 10:00:00'));

        $labels = $this->labelsOver($this->history('model:light', 'model:strong'), $logger)->steeringOf('alpha', $foreign);

        self::assertSame([], [...$labels]);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
    }

    public function testTwoValuesOfAFamilyTheDesignDoesNotNameAreBothKept(): void
    {
        $labels = $this->labelsOver($this->history('area:docs', 'area:api'), new NullLogger())->steeringOf('alpha', $this->open());

        self::assertSame(['area:docs', 'area:api'], [...$labels]);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->comments);
    }

    private function history(string ...$labels): IssueHistories
    {
        $histories = self::createStub(IssueHistories::class);
        $histories->method('of')->willReturn(new IssueHistory('alice', array_values(array_map(static fn (string $label): LabelApplication => new LabelApplication($label, RecordedTrust::WRITER), $labels)), '', new IssueLabels(...$labels)));

        return $histories;
    }

    private function labelsOver(IssueHistories $histories, LoggerInterface $logger): GitHubChangeLabels
    {
        $this->identity = new RecordedTrust()->client();
        $wiring = TrustChecks::wiring(new MockHttpClient($this->answer(...), 'https://api.github.com'));
        $repositories = new WatchedRepositories(['alpha' => ['github' => 'acme/widget']]);
        $outvoted = new OutvotedLabels(new GitHubLabelWrites($wiring->calls, $wiring->tokens), new GitHubIssueComments($wiring->calls, $wiring->tokens, $repositories, '12345'), $logger);

        return new GitHubChangeLabels(new TrustedPullRequestLabels($histories, $wiring->check, $repositories), $outvoted, $logger);
    }

    private function open(): OpenChange
    {
        return new OpenChange(new ChangeId('https://github.com/acme/widget/pull/7'), new CommitId(str_repeat('a', 40)), new DateTimeImmutable('2026-11-25 10:00:00'));
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        if (str_ends_with($path, '/issues/7/comments')) {
            $payload = (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->comments[] = is_string($payload['body'] ?? null) ? $payload['body'] : '';
            $stored = [...$payload, 'user' => ['login' => RecordedTrust::OWN_IDENTITY, 'type' => 'Bot'], 'created_at' => '2026-11-25T10:00:00Z', 'updated_at' => '2026-11-25T10:00:00Z', 'performed_via_github_app' => ['id' => 12345]];

            return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
        }
        if (str_contains($path, '/issues/7/labels')) {
            $this->writes[] = $method.' '.$path;

            return new MockResponse('[]', ['http_code' => 200]);
        }

        return ($this->identity ?? throw new LogicException('No identity client.'))->request($method, $url, $options);
    }
}
