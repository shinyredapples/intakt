<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizeTier;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\EligibleTasks;
use Intakt\Core\Build\UseCase\SizeTask\RecordedSizing;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingOutcome;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Build\UseCase\SizeTask\TaskNotSized;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskComments;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Intakt\Tests\Plugin\GitHubForge\ScriptedIssue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

// Forced order in every test: the first run's comment post fails after the park, and the retry runs on the same text.
#[CoversClass(SizeTask::class)]
#[CoversClass(RecordedSizing::class)]
#[CoversClass(SizingRecords::class)]
#[UsesClass(SizingOutcome::class)]
#[UsesClass(TaskNotSized::class)]
#[UsesClass(KeyedComment::class)]
#[UsesClass(GitHubTaskComments::class)]
#[UsesClass(GitHubIssueComments::class)]
#[UsesClass(IssueComment::class)]
#[UsesClass(TaskIssue::class)]
#[UsesClass(EligibleTasks::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectEligibilityLabel::class)]
#[UsesClass(SizingVerdict::class)]
#[UsesClass(SizingRequest::class)]
#[UsesClass(SizeTier::class)]
#[UsesClass(ReadinessGap::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(BuildRun::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(ParkedTaskRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(IssueLabels::class)]
#[UsesClass(LabelApplication::class)]
final class SizingRetryTest extends TestCase
{
    private int $calls = 0;

    public function testARetryAfterAStoredButFailedCommentDoesNotAskTheModelAgainWhenItWouldAnswerReady(): void
    {
        $fixture = $this->fixture();
        $issue = $this->issueThatStoresAndFails();
        $sizing = $fixture->sizing($this->vendorAnswering($this->notReady(ReadinessGap::AcceptanceCondition), $this->ready()), comments: new GitHubTaskComments($issue->comments()));
        $this->failedFirstRun($sizing);

        $outcome = $sizing->run(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER);

        self::assertSame(1, $this->calls);
        self::assertNotSame(SizingOutcome::Ready, $outcome);
        self::assertCount(1, $issue->posted());
        self::assertSame(['"This task is not ready to build. It lacks: an acceptance condition."'], $fixture->parked->fetchFirstColumn('SELECT question FROM build_parked_task'));
    }

    public function testARetryAfterAStoredButFailedCommentKeepsTheFirstGapWhenTheModelWouldNameAnother(): void
    {
        $fixture = $this->fixture();
        $issue = $this->issueThatStoresAndFails();
        $sizing = $fixture->sizing($this->vendorAnswering($this->notReady(ReadinessGap::AcceptanceCondition), $this->notReady(ReadinessGap::SingleOutcome)), comments: new GitHubTaskComments($issue->comments()));
        $this->failedFirstRun($sizing);

        (void) $sizing->run(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER);

        self::assertSame(1, $this->calls);
        self::assertCount(1, $issue->posted());
        self::assertStringStartsWith('This task is not ready to build. It lacks: an acceptance condition.', $issue->posted()[0]);
        self::assertSame(['"This task is not ready to build. It lacks: an acceptance condition."'], $fixture->parked->fetchFirstColumn('SELECT question FROM build_parked_task'));
        self::assertSame([['parked', ['acceptance_condition']]], array_map(static fn (array $payload): array => [$payload['outcome'], $payload['missing']], $this->sizings($fixture)));
    }

    public function testAParkedVerdictIsOnRecordWithTheParkingRunWhenItsCommentFails(): void
    {
        $fixture = $this->fixture();
        $sizing = $fixture->sizing($this->vendorAnswering($this->notReady(ReadinessGap::AcceptanceCondition)), comments: new GitHubTaskComments($this->issueThatStoresAndFails()->comments()));

        $this->failedFirstRun($sizing);

        self::assertSame($fixture->parked->fetchFirstColumn('SELECT run FROM build_parked_task'), array_column($this->sizings($fixture), 'run'));
    }

    public function testARetryPostsTheCommentAFailedRunDidNotStore(): void
    {
        $fixture = $this->fixture();
        $sizing = $fixture->sizing($this->vendorAnswering($this->notReady(ReadinessGap::AcceptanceCondition)));
        $fixture->comments->failure = new ForgeWriteFailed('GitHub answered 502 to a write.');
        $this->failedFirstRun($sizing);
        $fixture->comments->failure = null;

        self::assertSame(SizingOutcome::AlreadySized, $sizing->run(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER));
        self::assertCount(1, $fixture->comments->posted);
        self::assertCount(1, $this->sizings($fixture));
    }

    public function testARetryWhoseCommentsCannotBeReadFailsTheSizingWithoutANewRecord(): void
    {
        $fixture = $this->fixture();
        $sizing = $fixture->sizing($this->vendorAnswering($this->notReady(ReadinessGap::AcceptanceCondition)));
        $fixture->comments->failure = new ForgeWriteFailed('GitHub answered 502 to a write.');
        $this->failedFirstRun($sizing);
        $fixture->comments->unreadable = new ForgeReadFailed('GitHub answered 500 to a read of the comments.');

        $this->failedFirstRun($sizing);

        self::assertSame(1, $this->calls);
        self::assertCount(1, $this->sizings($fixture));
    }

    public function testARecordedParkWithoutKnownMissingItemsIsSizedAgain(): void
    {
        $fixture = $this->fixture();
        $request = new SizingRequest(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER, 'Add a cart page', SampleIssue::BODY);
        (void) $fixture->ledger->append(new LedgerEvent(LedgerEventKind::TaskSized, (string) json_encode(['outcome' => 'parked', 'missing' => ['unknown_gap'], 'text' => SizingRecords::textOf($request), 'run' => 'r']), SizingRecords::keyOf(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER)));

        self::assertSame(SizingOutcome::Ready, $fixture->sizing($this->vendorAnswering($this->ready()))->run(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER));
        self::assertSame(1, $this->calls);
    }

    private function failedFirstRun(SizeTask $sizing): void
    {
        try {
            (void) $sizing->run(ScriptedIssue::PROJECT, ScriptedIssue::NUMBER);
            self::fail('A run whose comment failed was reported as sized.');
        } catch (TaskNotSized $notSized) {
            self::assertStringContainsString('but its comment was not posted', $notSized->getMessage());
        }
    }

    private function issueThatStoresAndFails(): ScriptedIssue
    {
        $issue = new ScriptedIssue();
        $issue->answerToAStoredPost = 502;

        return $issue;
    }

    private function vendorAnswering(SizingVerdict ...$verdicts): SizingVendor
    {
        $vendor = self::createStub(SizingVendor::class);
        $vendor->method('size')->willReturnCallback(function () use ($verdicts): SizingVerdict {
            return $verdicts[$this->calls++] ?? self::fail('The model was asked more often than it was scripted for.');
        });

        return $vendor;
    }

    private function notReady(ReadinessGap $gap): SizingVerdict
    {
        return new SizingVerdict(false, [$gap], SizeTier::Small, 20, 'Incomplete.');
    }

    private function ready(): SizingVerdict
    {
        return new SizingVerdict(true, [], SizeTier::Small, 20, 'Reassessed.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sizings(SizingFixture $fixture): array
    {
        return array_values(array_filter($fixture->ledger->payloads(), static fn (array $payload): bool => isset($payload['text'])));
    }

    private function fixture(): SizingFixture
    {
        return new SizingFixture(self::createStub(ManagerRegistry::class));
    }
}
