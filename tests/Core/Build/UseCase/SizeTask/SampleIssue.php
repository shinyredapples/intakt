<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Override;

// One open issue and its history, written by an owner with write access and labeled eligible by them, because sizing reads one task and asks who steered it.
final class SampleIssue implements IssueReads, IssueHistories
{
    public const string LABEL = 'ready';
    public const string OWNER = 'owner';
    public const string BODY = 'Users see their items on /cart.';

    public ?ListedIssue $open;

    public IssueHistory $history;

    public ?ForgeReadFailed $failure = null;

    public function __construct(int $number = 7)
    {
        $this->open = self::listed($number, self::OWNER, self::BODY);
        $this->history = new IssueHistory(self::OWNER, [new LabelApplication(self::LABEL, self::OWNER)], self::BODY, new IssueLabels(self::LABEL));
    }

    public static function listed(int $number, string $author, string $body, string ...$labels): ListedIssue
    {
        return new ListedIssue($number, 'Add a cart page', $body, new IssueLabels(...([] === $labels ? [self::LABEL] : $labels)), $author, new DateTimeImmutable('2026-11-20 10:00:00 UTC'), null, false);
    }

    #[Override]
    public function listOpen(string $project, ?string $validator): IssueListing
    {
        throw new ForgeReadFailed('Sizing lists no issues.');
    }

    #[Override]
    public function readOpen(string $project, int $number): ?ListedIssue
    {
        return null === $this->failure ? $this->open : throw $this->failure;
    }

    #[Override]
    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory
    {
        return $this->history;
    }
}
