<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\TaskCloses;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TaskCloses::class)]
final readonly class GitHubTaskCloses implements TaskCloses
{
    public function __construct(
        private GitHubTaskComments $comments,
        private PartIssueCalls $calls,
        private WatchedRepositories $repositories,
    ) {
    }

    #[Override]
    public function closeAsCompleted(string $project, int $number, KeyedComment $comment): void
    {
        $this->comments->postOnce($project, $number, $comment);

        $closed = $this->calls->written('PATCH', $this->repositories->addressOf($project)->path().'/issues/'.$number, ['state' => 'closed', 'state_reason' => 'completed']);

        // Checked, because an answer that is not the closed issue says nothing about whether GitHub closed it, and the next listing would show it open anyway.
        if ($number !== ($closed['number'] ?? null) || 'closed' !== ($closed['state'] ?? null)) {
            throw new ForgeWriteFailed(sprintf('GitHub answered the close of #%d without that issue closed.', $number));
        }
    }
}
