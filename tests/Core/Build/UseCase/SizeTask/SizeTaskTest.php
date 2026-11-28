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
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SizeTask::class)]
#[CoversClass(SizingRecords::class)]
#[CoversClass(TaskNotSized::class)]
#[CoversClass(SizingOutcome::class)]
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
final class SizeTaskTest extends TestCase
{
    public function testAReadyTaskGetsAStoredSizeEstimateAndIsNotParked(): void
    {
        $fixture = $this->fixture();

        $outcome = $fixture->sizing($this->vendor($this->verdict([], SizeTier::Medium, 240)))->run('alpha', 7);

        self::assertSame(SizingOutcome::Ready, $outcome);
        self::assertSame([['project' => 'alpha', 'task' => '7', 'outcome' => 'ready', 'missing' => [], 'size' => 'medium', 'estimated_lines' => 240]], $this->recorded($fixture));
        self::assertSame([LedgerEventKind::TaskSized], $fixture->ledger->kinds());
        self::assertSame([SizingRecords::keyOf('alpha', 7)], $fixture->ledger->subjects());
        self::assertSame([], $fixture->parked->fetchAllAssociative('SELECT * FROM build_parked_task'));
        self::assertSame([], $fixture->comments->posted);
    }

    public function testATaskWithoutAnAcceptanceConditionIsParkedWithExactlyOneQuestionAndOneComment(): void
    {
        $fixture = $this->fixture();

        $outcome = $fixture->sizing($this->vendor($this->verdict([ReadinessGap::AcceptanceCondition], SizeTier::Small, 20)))->run('alpha', 7);

        self::assertSame(SizingOutcome::Parked, $outcome);
        self::assertSame('"This task is not ready to build. It lacks: an acceptance condition."', $fixture->parked->fetchOne('SELECT question FROM build_parked_task'));
        self::assertSame(1, $fixture->parked->fetchOne('SELECT COUNT(*) FROM build_parked_task'));
        self::assertSame([['alpha', 7, "This task is not ready to build. It lacks: an acceptance condition.\n\n- Add the missing items to the task\n- Close the task"]], $fixture->comments->posted);
        self::assertSame([LedgerEventKind::BuildStepEnded, LedgerEventKind::TaskParked, LedgerEventKind::TaskSized], $fixture->ledger->kinds());
    }

    public function testATaskWithTwoOutcomesIsParkedNamingTheMissingItem(): void
    {
        $fixture = $this->fixture();

        (void) $fixture->sizing($this->vendor($this->verdict([ReadinessGap::SingleOutcome], SizeTier::Large, 900)))->run('alpha', 7);

        $question = $fixture->parked->fetchOne('SELECT question FROM build_parked_task');
        self::assertIsString($question);
        self::assertStringContainsString('a single outcome', $question);
    }

    public function testAnInvalidSizingAnswerParksNothingSplitsNothingAndPostsNothing(): void
    {
        $fixture = $this->fixture();

        $outcome = $fixture->sizing($this->vendor(null))->run('alpha', 7);

        self::assertSame(SizingOutcome::Invalid, $outcome);
        self::assertSame([['project' => 'alpha', 'task' => '7', 'outcome' => 'invalid', 'missing' => [], 'size' => null, 'estimated_lines' => null]], $this->recorded($fixture));
        self::assertSame([], $fixture->parked->fetchAllAssociative('SELECT * FROM build_parked_task'));
        self::assertSame([], $fixture->comments->posted);
    }

    public function testAnIssueThatIsNotOpenIsNotSizedAndCostsNoCall(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->open = null;

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'it is not an open issue'));

        (void) $fixture->sizing($this->unusedVendor())->run('alpha', 7);
    }

    public function testAnIssueTheForgeCannotReadIsNotSized(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->failure = new ForgeReadFailed('the forge is down');

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'the forge is down'));

        (void) $fixture->sizing($this->unusedVendor())->run('alpha', 7);
    }

    public function testAnIssueOfAnAuthorWithoutWriteAccessIsNotSizedAndCostsNoCall(): void
    {
        $fixture = $this->fixture();

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'its author has no write access'));

        (void) $fixture->sizing($this->unusedVendor(), new SteeringLogins('someone-else'))->run('alpha', 7);
    }

    public function testAReadyVerdictTheLedgerDoesNotRecordFailsTheSizing(): void
    {
        $fixture = $this->fixture(new InMemoryLedger(0));

        try {
            (void) $fixture->sizing($this->vendor($this->verdict([], SizeTier::Small, 10)))->run('alpha', 7);
            self::fail('A verdict the ledger refused was reported as recorded.');
        } catch (TaskNotSized $notSized) {
            self::assertStringContainsString('did not record the sizing', $notSized->getMessage());
        }
    }

    public function testAParkingThatFailsRecordsNoParkedOutcomeAndPostsNoComment(): void
    {
        $fixture = $this->fixture();
        // An open transaction makes ParkedTasks refuse the park, because it parks only in its own transaction.
        $fixture->parked->beginTransaction();

        try {
            (void) $fixture->sizing($this->vendor($this->verdict([ReadinessGap::AcceptanceCondition], SizeTier::Small, 20)))->run('alpha', 7);
            self::fail('A park that failed was reported as parked.');
        } catch (TaskNotSized $notSized) {
            self::assertSame('Issue 7 of project "alpha" is not ready, and parking it failed.', $notSized->getMessage());
        } finally {
            $fixture->parked->rollBack();
        }

        self::assertNotContains(LedgerEventKind::TaskSized, $fixture->ledger->kinds());
        self::assertSame([], $fixture->comments->posted);
    }

    public function testACommentThatFailsRecordsNoParkedOutcome(): void
    {
        $fixture = $this->fixture();
        $fixture->comments->failure = new ForgeWriteFailed('GitHub answered 502 to a write.');

        try {
            (void) $fixture->sizing($this->vendor($this->verdict([ReadinessGap::AcceptanceCondition], SizeTier::Small, 20)))->run('alpha', 7);
            self::fail('A comment that failed was reported as posted.');
        } catch (TaskNotSized $notSized) {
            self::assertSame('Issue 7 of project "alpha" is parked, but its comment was not posted: GitHub answered 502 to a write.', $notSized->getMessage());
        }

        self::assertNotContains(LedgerEventKind::TaskSized, $fixture->ledger->kinds());
    }

    public function testASizingThatOverlapsAnotherOfTheSameTaskIsRefusedAndTheTaskIsParkedOnce(): void
    {
        $fixture = $this->fixture();
        $vendor = self::createStub(SizingVendor::class);
        $sizing = $fixture->sizing($vendor);
        $calls = 0;
        $overlapping = null;
        $vendor->method('size')->willReturnCallback(function () use (&$calls, &$overlapping, $sizing): SizingVerdict {
            if (1 === ++$calls) {
                try {
                    (void) $sizing->run('alpha', 7);
                } catch (TaskNotSized $refused) {
                    $overlapping = $refused->getMessage();
                }
            }

            return $this->verdict([ReadinessGap::AcceptanceCondition], SizeTier::Small, 20);
        });

        self::assertSame(SizingOutcome::Parked, $sizing->run('alpha', 7));
        self::assertSame(1, $calls);
        self::assertSame('Issue 7 of project "alpha" is not sized: another sizing of it is running.', $overlapping);
        self::assertSame([LedgerEventKind::TaskParked], array_values(array_filter($fixture->ledger->kinds(), static fn (LedgerEventKind $kind): bool => LedgerEventKind::TaskParked === $kind)));
        self::assertCount(1, $fixture->comments->posted);
    }

    public function testATaskAlreadySizedWithTheSameTextIsNotSizedAgain(): void
    {
        $fixture = $this->fixture();
        (void) $fixture->sizing($this->vendor($this->verdict([ReadinessGap::AcceptanceCondition], SizeTier::Small, 20)))->run('alpha', 7);

        $outcome = $fixture->sizing($this->unusedVendor())->run('alpha', 7);

        self::assertSame(SizingOutcome::AlreadySized, $outcome);
        self::assertCount(1, $fixture->comments->posted);
    }

    public function testATaskEditedSinceItsVerdictIsSizedAgain(): void
    {
        $fixture = $this->fixture();
        (void) $fixture->sizing($this->vendor($this->verdict([ReadinessGap::AcceptanceCondition], SizeTier::Small, 20)))->run('alpha', 7);
        $fixture->issue->open = SampleIssue::listed(7, SampleIssue::OWNER, 'Users see their items on /cart. Done when the page lists them.');
        $fixture->issue->history = new IssueHistory(SampleIssue::OWNER, [new LabelApplication(SampleIssue::LABEL, SampleIssue::OWNER)], 'Users see their items on /cart. Done when the page lists them.', new IssueLabels(SampleIssue::LABEL));

        $outcome = $fixture->sizing($this->vendor($this->verdict([], SizeTier::Small, 20)))->run('alpha', 7);

        self::assertSame(SizingOutcome::Ready, $outcome);
    }

    public function testATaskWhoseLastAnswerWasInvalidIsSizedAgain(): void
    {
        $fixture = $this->fixture();
        (void) $fixture->sizing($this->vendor(null))->run('alpha', 7);

        $outcome = $fixture->sizing($this->vendor($this->verdict([], SizeTier::Small, 20)))->run('alpha', 7);

        self::assertSame(SizingOutcome::Ready, $outcome);
    }

    public function testAVerdictOnAnotherProjectsTaskOfTheSameNumberDoesNotCount(): void
    {
        $fixture = $this->fixture();
        (void) $fixture->sizing($this->vendor($this->verdict([], SizeTier::Small, 20)))->run('alpha', 7);

        $outcome = $fixture->sizing($this->vendor($this->verdict([], SizeTier::Small, 20)))->run('sample', 7);

        self::assertSame(SizingOutcome::Ready, $outcome);
    }

    private function fixture(InMemoryLedger $ledger = new InMemoryLedger()): SizingFixture
    {
        return new SizingFixture(self::createStub(ManagerRegistry::class), $ledger);
    }

    /**
     * @param list<ReadinessGap> $missing
     */
    private function verdict(array $missing, SizeTier $size, int $lines): SizingVerdict
    {
        return new SizingVerdict([] === $missing, $missing, $size, $lines, 'Because.');
    }

    private function vendor(?SizingVerdict $verdict): SizingVendor
    {
        $vendor = self::createStub(SizingVendor::class);
        $vendor->method('size')->willReturn($verdict);

        return $vendor;
    }

    private function unusedVendor(): SizingVendor
    {
        $vendor = $this->createMock(SizingVendor::class);
        $vendor->expects(self::never())->method('size');

        return $vendor;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recorded(SizingFixture $fixture): array
    {
        return array_map(static fn (array $payload): array => array_diff_key($payload, ['text' => true]), $fixture->ledger->payloads());
    }
}
