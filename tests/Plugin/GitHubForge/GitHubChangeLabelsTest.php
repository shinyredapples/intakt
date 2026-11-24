<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubChangeLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustedPullRequestLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(GitHubChangeLabels::class)]
#[UsesClass(TrustedPullRequestLabels::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
final class GitHubChangeLabelsTest extends TestCase
{
    public function testAFailedReadCountsNoSteeringLabel(): void
    {
        $histories = self::createStub(IssueHistories::class);
        $histories->method('of')->willThrowException(new ForgeReadFailed('down'));
        $trusted = new TrustedPullRequestLabels($histories, TrustChecks::over(new RecordedTrust()), new WatchedRepositories(['alpha' => ['github' => 'acme/widget']]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $labels = new GitHubChangeLabels($trusted, $logger)->steeringOf('alpha', new OpenChange(new ChangeId('https://github.com/acme/widget/pull/7'), new CommitId(str_repeat('a', 40)), new DateTimeImmutable('2026-11-25 10:00:00')));

        self::assertSame([], [...$labels]);
    }
}
