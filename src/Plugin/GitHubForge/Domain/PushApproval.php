<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use NoDiscard;

// Only the owner's own words for exactly this head count, because a push runs the change's workflows on the project's runners before anyone else looked.
final readonly class PushApproval
{
    private const string COMMAND = 'approve ';

    /**
     * @param list<IssueComment> $comments
     */
    #[NoDiscard]
    public function isGivenIn(array $comments, string $owner, string $head): bool
    {
        return '' !== $owner && array_any($comments, fn (IssueComment $comment): bool => $this->approves($comment, $owner, $head));
    }

    // The full commit id only, because a builder can make a commit whose short id matches the one the owner approved; an edited comment never counts, because a repository admin or an App can edit the owner's words.
    private function approves(IssueComment $comment, string $owner, string $head): bool
    {
        return $comment->byPerson
            && !$comment->edited
            && 0 === strcasecmp($comment->author, $owner)
            && self::COMMAND.strtolower($head) === strtolower(trim($comment->body));
    }
}
