<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// A `304` compares representations, and `updated_at` counts whole seconds, so an issue closed and reopened in the second its page was served looks unchanged while a later page was read without the issue it pushed back; only an issue updated before that second rules this out.
final readonly class ChangedWhileServed
{
    // Because the answer's Date and an issue's `updated_at` come from different GitHub servers, whose clocks may differ by this much; a skew of exactly this much still hides a reopen, so the limit itself is refused.
    private const int CLOCK_SLACK_SECONDS = 5;

    public function __construct(private GitHubMoments $moments)
    {
    }

    /**
     * @param list<ListedIssue> $listed
     *
     * @throws ForgeReadFailed
     */
    public function refuse(array $listed, ?string $date, int $page): void
    {
        $served = $this->moments->served($date ?? throw new ForgeReadFailed(sprintf('GitHub answered page %d of open issues without a Date.', $page)));
        $since = $served->modify(sprintf('-%d seconds', self::CLOCK_SLACK_SECONDS));
        $recent = array_find($listed, static fn (ListedIssue $issue): bool => $issue->updatedAt >= $since);
        if (null !== $recent) {
            throw new ForgeReadFailed(sprintf('Issue #%d on page %d of the open issues changed in the seconds GitHub served the page, so a close and reopen while the later pages were read cannot be ruled out.', $recent->number, $page));
        }
    }

    // The 304s confirm the earlier pages one at a time, so an issue that opens ahead of a page boundary for one read and is gone for every confirmation passes them, yet lists the next page's first issue twice; a gap instead needs a listed issue to close and reopen, which changes its `updated_at` and so its page's `ETag`.
    /**
     * @param list<ListedIssue> $issues
     *
     * @throws ForgeReadFailed
     */
    public function refuseListedTwice(array $issues): void
    {
        $twice = array_find_key(array_count_values(array_map(static fn (ListedIssue $issue): int => $issue->number, $issues)), static fn (int $count): bool => $count > 1);
        if (null !== $twice) {
            throw new ForgeReadFailed(sprintf('GitHub listed issue #%d on two pages, so the open issues moved while they were read.', $twice));
        }
    }
}
