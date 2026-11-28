<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskCloses;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskComments;
use Intakt\Plugin\GitHubForge\Infrastructure\PartIssueCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubTaskCloses::class)]
#[UsesClass(GitHubTaskComments::class)]
#[UsesClass(GitHubIssueComments::class)]
#[UsesClass(PartIssueCalls::class)]
#[UsesClass(IssueComment::class)]
#[UsesClass(KeyedComment::class)]
#[UsesClass(TaskIssue::class)]
final class GitHubTaskClosesTest extends TestCase
{
    private const string TEXT = 'All parts are closed (#201, #202), so this umbrella is closed.';
    private const string STORED = self::TEXT."\n\n<!-- intakt:once umbrella-closed -->";

    public function testClosingAnUmbrellaPostsOneKeyedCommentThenClosesItAsCompleted(): void
    {
        $issue = new ScriptedIssue();

        $this->close($issue);

        self::assertSame([self::STORED], $issue->posted());
        self::assertSame([['state' => 'closed', 'state_reason' => 'completed']], $issue->changes);
        self::assertSame('PATCH https://api.github.com/repos/acme/widget/issues/7', array_last($issue->requests));
    }

    public function testAFailedCloseIsRetriedWithoutASecondComment(): void
    {
        $issue = new ScriptedIssue();
        $issue->closeRefusal = 502;
        try {
            $this->close($issue);
            self::fail('A close answered with 502 was reported as done.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('GitHub answered 502 to a write.', $failed->getMessage());
        }

        $issue->closeRefusal = null;
        $this->close($issue);

        self::assertSame([self::STORED], $issue->posted());
        self::assertSame('closed', $issue->state);
    }

    public function testCommentsThatCannotBeReadCloseNothing(): void
    {
        $issue = new ScriptedIssue();
        $issue->refusal = 500;

        $this->expectException(ForgeReadFailed::class);
        try {
            $this->close($issue);
        } finally {
            self::assertSame([], $issue->changes);
        }
    }

    public function testAnAnswerThatDoesNotShowTheIssueClosedFailsTheClose(): void
    {
        $issue = new ScriptedIssue();
        $issue->answeredState = 'open';

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered the close of #7 without that issue closed.'));

        $this->close($issue);
    }

    private function close(ScriptedIssue $issue): void
    {
        $issue->closes()->closeAsCompleted(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER, new KeyedComment(self::TEXT, 'umbrella-closed'));
    }
}
