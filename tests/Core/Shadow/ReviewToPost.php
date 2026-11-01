<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\ReviewToken;

final class ReviewToPost
{
    public static function change(string $id): Change
    {
        return new Change(new ChangeId($id), new CommitRange(new CommitId(str_repeat('a', 40)), new CommitId(str_repeat('b', 40))), new Diff('', new ChangedFiles()));
    }

    public static function comment(string $body): ReviewComment
    {
        return new ReviewComment($body, new ReviewToken('shadow-round-token-0001'), new JobCredentials());
    }
}
