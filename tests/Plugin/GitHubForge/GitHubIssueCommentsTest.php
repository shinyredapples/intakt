<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubIssueComments::class)]
#[UsesClass(IssueComment::class)]
#[UsesClass(TaskIssue::class)]
final class GitHubIssueCommentsTest extends TestCase
{
    public function testEveryPageIsReadWithEachCommentsAuthorAndWhetherItWasEditedOrPostedByThisApp(): void
    {
        $issue = new ScriptedIssue();
        foreach (range(1, 100) as $number) {
            $issue->byPerson('Comment '.$number, 'someone');
        }
        $issue->editedByOwner('approve abc');
        $issue->comments()->post(ScriptedIssue::task(), 'The request.');

        $comments = $issue->comments()->of(ScriptedIssue::task());

        self::assertCount(102, $comments);
        self::assertEquals(new IssueComment('owner', true, true, false, 'approve abc'), $comments[100]);
        self::assertEquals(new IssueComment('intakt[bot]', false, false, true, 'The request.'), $comments[101]);
        self::assertContains('GET https://api.github.com/repos/acme/widget/issues/7/comments?per_page=100&page=2', $issue->requests);
    }

    public function testACommentAnotherAppPostedWithAPersonsTokenIsNotByAPerson(): void
    {
        $issue = new ScriptedIssue();
        $issue->byOwnerThroughAnApp('approve abc', 999);

        $comments = $issue->comments()->of(ScriptedIssue::task());

        self::assertEquals([new IssueComment('owner', false, false, false, 'approve abc')], $comments);
    }

    public function testAnIssueWithMoreCommentsThanThirtyFullPagesFailsTheRead(): void
    {
        $issue = new ScriptedIssue();
        foreach (range(1, 3000) as $number) {
            $issue->byPerson('Comment '.$number);
        }

        $this->expectExceptionObject(new ForgeReadFailed('The issue holds more comments than this forge reads.'));

        $issue->comments()->of(ScriptedIssue::task());
    }

    public function testACommentWithoutItsAuthorFailsTheRead(): void
    {
        $issue = new ScriptedIssue();
        $issue->comments[] = ['body' => 'approve abc', 'created_at' => 'x', 'updated_at' => 'x'];

        $this->expectExceptionObject(new ForgeReadFailed('GitHub listed a comment without its author, text or times.'));

        $issue->comments()->of(ScriptedIssue::task());
    }

    public function testARefusedReadSaysTheCredentialsWereRefused(): void
    {
        $issue = new ScriptedIssue();
        $issue->refusal = 401;

        try {
            $issue->comments()->of(ScriptedIssue::task());
            self::fail('A refused read must fail.');
        } catch (ForgeReadFailed $failed) {
            self::assertSame('GitHub answered 401 to a read of the comments.', $failed->getMessage());
            self::assertInstanceOf(ForgeCredentialsRefused::class, $failed->getPrevious());
        }
    }

    public function testARefusedPostFails(): void
    {
        $issue = new ScriptedIssue();
        $issue->refusal = 422;

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered 422 to a write.'));

        $issue->comments()->post(ScriptedIssue::task(), 'The request.');
    }

    public function testAProjectWithoutAGitHubRepositoryPostsNothing(): void
    {
        $issue = new ScriptedIssue();

        $this->expectExceptionObject(new ForgeWriteFailed('Project "other" has no GitHub repository in its watch section.'));

        $issue->comments()->post(new TaskIssue('other', ScriptedIssue::NUMBER), 'The request.');
    }

    public function testAnIssueNumberBelowOneIsRefusedBeforeAnyCall(): void
    {
        $issue = new ScriptedIssue();

        try {
            $issue->comments()->of(new TaskIssue(ScriptedIssue::PROJECT, 0));
            self::fail('Issue 0 must be refused.');
        } catch (ForgeReadFailed $failed) {
            self::assertSame('0 is no issue number.', $failed->getMessage());
            self::assertSame([], $issue->requests);
        }
    }
}
