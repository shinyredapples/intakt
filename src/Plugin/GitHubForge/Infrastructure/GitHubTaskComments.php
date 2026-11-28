<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\TaskComments;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TaskComments::class)]
final readonly class GitHubTaskComments implements TaskComments
{
    public function __construct(private GitHubIssueComments $comments)
    {
    }

    #[Override]
    public function postOnce(string $project, int $number, KeyedComment $comment): void
    {
        $issue = new TaskIssue($project, $number);
        $marker = $this->markerOf($comment->key);

        // By this App's mark and the marker, never by the visible wording, because anyone can write the marker into a comment of their own, and the same wording is owed again for another key.
        $stored = array_any($this->comments->of($issue), static fn (IssueComment $held): bool => $held->byThisApp && str_contains($held->body, $marker));

        if (!$stored) {
            $this->comments->post($issue, $comment->body."\n\n".$marker);
        }
    }

    // An HTML comment, because GitHub keeps it in the stored text and shows none of it to the reader.
    /**
     * @throws ForgeWriteFailed
     */
    private function markerOf(string $key): string
    {
        if (1 !== preg_match('/\A[a-z0-9-]+\z/', $key)) {
            throw new ForgeWriteFailed('The key of a comment posted once holds other characters than lowercase letters, digits and hyphens.');
        }

        return sprintf('<!-- intakt:once %s -->', $key);
    }
}
