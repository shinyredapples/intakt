<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubTaskComments::class)]
#[UsesClass(GitHubIssueComments::class)]
#[UsesClass(IssueComment::class)]
#[UsesClass(KeyedComment::class)]
#[UsesClass(TaskIssue::class)]
final class GitHubTaskCommentsTest extends TestCase
{
    private const string TEXT = 'This task is not ready to build.';
    private const string STORED = self::TEXT."\n\n<!-- intakt:once sizing-abc123 -->";

    public function testACommentIsPostedOnTheTasksIssueWithItsKeyAsAHiddenMarker(): void
    {
        $issue = new ScriptedIssue();

        $this->postOnce($issue, 'sizing-abc123');

        self::assertSame([self::STORED], $issue->posted());
        self::assertContains('POST https://api.github.com/repos/acme/widget/issues/7/comments', $issue->requests);
    }

    public function testACommentOfThisAppThatCarriesTheKeyIsNotPostedAgain(): void
    {
        $issue = new ScriptedIssue();
        $this->postOnce($issue, 'sizing-abc123');

        $this->postOnce($issue, 'sizing-abc123');

        self::assertSame([self::STORED], $issue->posted());
    }

    public function testARetryAfterACommentGitHubStoredButAnsweredAsFailedPostsNoSecondComment(): void
    {
        $issue = new ScriptedIssue();
        $issue->answerToAStoredPost = 502;

        try {
            $this->postOnce($issue, 'sizing-abc123');
            self::fail('A post answered with 502 was reported as posted.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('GitHub answered 502 to a write.', $failed->getMessage());
        }

        $this->postOnce($issue, 'sizing-abc123');

        self::assertSame([self::STORED], $issue->posted());
    }

    public function testTheSameWordingUnderAnotherKeyIsPostedAgain(): void
    {
        $issue = new ScriptedIssue();
        $this->postOnce($issue, 'sizing-abc123');

        $this->postOnce($issue, 'sizing-def456');

        self::assertSame([self::STORED, self::TEXT."\n\n<!-- intakt:once sizing-def456 -->"], $issue->posted());
    }

    public function testAPersonsCommentThatCarriesTheMarkerDoesNotStandForThePost(): void
    {
        $issue = new ScriptedIssue();
        $issue->byPerson(self::STORED, 'stranger');

        $this->postOnce($issue, 'sizing-abc123');

        self::assertSame([self::STORED], $issue->posted());
    }

    public function testAnotherAppsCommentThatCarriesTheMarkerDoesNotStandForThePost(): void
    {
        $issue = new ScriptedIssue();
        $issue->byOwnerThroughAnApp(self::STORED, 999);

        $this->postOnce($issue, 'sizing-abc123');

        self::assertCount(2, $issue->posted());
    }

    public function testCommentsThatCannotBeReadPostNothing(): void
    {
        $issue = new ScriptedIssue();
        $issue->refusal = 500;

        try {
            $this->postOnce($issue, 'sizing-abc123');
            self::fail('A comment was posted without the read of the ones the issue holds.');
        } catch (ForgeReadFailed $failed) {
            self::assertSame('GitHub answered 500 to a read of the comments.', $failed->getMessage());
        }

        self::assertSame(['GET https://api.github.com/repos/acme/widget/issues/7/comments?per_page=100&page=1'], $issue->requests);
    }

    #[TestWith(['sizing --> injected'])]
    #[TestWith(['Sizing'])]
    #[TestWith([''])]
    public function testAKeyThatCouldBreakTheMarkerIsRefusedBeforeAnyRequest(string $key): void
    {
        $issue = new ScriptedIssue();

        try {
            $this->postOnce($issue, $key);
            self::fail('A key with other characters was posted.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('The key of a comment posted once holds other characters than lowercase letters, digits and hyphens.', $failed->getMessage());
        }

        self::assertSame([], $issue->requests);
    }

    private function postOnce(ScriptedIssue $issue, string $key): void
    {
        new GitHubTaskComments($issue->comments())->postOnce(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER, new KeyedComment(self::TEXT, $key));
    }
}
