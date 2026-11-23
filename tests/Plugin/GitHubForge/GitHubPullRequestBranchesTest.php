<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\BranchPullRequest;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubPullRequestBranches;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubPullRequestBranches::class)]
#[CoversClass(BranchPullRequest::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(GitHubMoments::class)]
#[UsesClass(GitHubCalls::class)]
final class GitHubPullRequestBranchesTest extends TestCase
{
    public function testEachOpenPullRequestIsListedWithItsBranchAuthorAndUpdateTime(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page([$this->pull(12, 'feat/7-add-intake', 'bea')])];

        $listed = $api->pullRequestBranches()->openOf('alpha');

        self::assertEquals([new BranchPullRequest(12, 'feat/7-add-intake', 'bea', new DateTimeImmutable('2026-11-10T10:00:00Z'))], $listed);
        self::assertSame(['GET https://api.github.com/repos/acme/widgets/pulls?state=open&per_page=100&page=1'], $api->requests);
    }

    public function testAFullPageIsFollowedByTheNextUntilAShortPage(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(array_map(fn (int $number): array => $this->pull($number, 'fix/'.$number), range(1, 100))), 2 => $this->page([$this->pull(101, 'fix/101')])];

        $listed = $api->pullRequestBranches()->openOf('alpha');

        self::assertSame(range(1, 101), array_map(static fn (BranchPullRequest $pull): int => $pull->number, $listed));
        self::assertCount(2, $api->requests);
    }

    public function testAPullRequestThatMovedOntoTheNextPageIsListedOnce(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(array_map(fn (int $number): array => $this->pull($number, 'fix/'.$number), range(1, 100))), 2 => $this->page([$this->pull(100, 'fix/100')])];

        self::assertCount(100, $api->pullRequestBranches()->openOf('alpha'));
    }

    public function testMoreFullPagesThanTheForgeReadsFailTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = array_combine(range(1, 11), array_map(fn (int $page): string => $this->page(array_map(fn (int $number): array => $this->pull($number, 'fix/'.$number), range($page * 100 - 99, $page * 100))), range(1, 11)));

        self::assertSame('GitHub lists more open pull requests than this forge reads.', $this->failureOf($api)->getMessage());
        self::assertCount(10, $api->requests);
    }

    public function testAPullRequestWithoutItsBranchFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page([[...$this->pull(12, 'feat/7'), 'head' => ['ref' => '']]])];

        self::assertSame('GitHub listed a pull request without "head.ref".', $this->failureOf($api)->getMessage());
    }

    public function testAPullRequestWithoutItsAuthorFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page([[...$this->pull(12, 'feat/7'), 'user' => null]])];

        self::assertSame('GitHub listed a pull request without "user.login".', $this->failureOf($api)->getMessage());
    }

    public function testAPullRequestWithoutANumberFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page([[...$this->pull(12, 'feat/7'), 'number' => '12']])];

        self::assertSame('GitHub listed a pull request without a number.', $this->failureOf($api)->getMessage());
    }

    public function testAnAnswerThatIsAnObjectFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => '{}'];

        self::assertSame('GitHub answered a listing of pull requests that is not a list.', $this->failureOf($api)->getMessage());
    }

    public function testAPageGitHubRefusesFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => '502'];

        self::assertSame('GitHub answered 502 to a read.', $this->failureOf($api)->getMessage());
    }

    public function testAProjectWithoutARepositoryFailsBeforeAnyRead(): void
    {
        $api = new RecordedIssueApi();

        $failure = null;
        try {
            $api->pullRequestBranches()->openOf('beta');
        } catch (ForgeReadFailed $failure) {
        }

        self::assertInstanceOf(ForgeReadFailed::class, $failure);
        self::assertSame([], $api->requests);
    }

    /**
     * @return array<string, mixed>
     */
    private function pull(int $number, string $branch, string $author = 'ana'): array
    {
        return ['number' => $number, 'head' => ['ref' => $branch], 'user' => ['login' => $author], 'updated_at' => '2026-11-10T10:00:00Z'];
    }

    /**
     * @param list<array<string, mixed>> $pulls
     */
    private function page(array $pulls): string
    {
        return json_encode($pulls, JSON_THROW_ON_ERROR);
    }

    private function failureOf(RecordedIssueApi $api): ForgeReadFailed
    {
        try {
            $api->pullRequestBranches()->openOf('alpha');
        } catch (ForgeReadFailed $failed) {
            return $failed;
        }

        self::fail('The listing was expected to fail.');
    }
}
