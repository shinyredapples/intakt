<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubTaskComments::class)]
#[UsesClass(GitHubIssueComments::class)]
#[UsesClass(IssueComment::class)]
#[UsesClass(TaskIssue::class)]
final class GitHubTaskCommentsTest extends TestCase
{
    public function testACommentIsPostedOnTheTasksIssue(): void
    {
        $issue = new ScriptedIssue();
        $task = ScriptedIssue::task();

        new GitHubTaskComments($issue->comments())->post($task->project, $task->number, 'This task is not ready to build.');

        self::assertSame(['This task is not ready to build.'], $issue->posted());
        self::assertContains('POST https://api.github.com/repos/acme/widget/issues/7/comments', $issue->requests);
    }

    public function testARefusedCommentFails(): void
    {
        $issue = new ScriptedIssue();
        $issue->refusal = 422;
        $task = ScriptedIssue::task();

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered 422 to a write.'));

        new GitHubTaskComments($issue->comments())->post($task->project, $task->number, 'This task is not ready to build.');
    }
}
