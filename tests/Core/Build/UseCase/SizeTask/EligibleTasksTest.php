<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\UseCase\SizeTask\EligibleTasks;
use Intakt\Core\Build\UseCase\SizeTask\TaskNotSized;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Core\Watcher\Contract\ProjectNamesNoEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EligibleTasks::class)]
#[UsesClass(TaskNotSized::class)]
#[UsesClass(SizingRequest::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectEligibilityLabel::class)]
#[UsesClass(ProjectNamesNoEligibilityLabel::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(IssueLabels::class)]
#[UsesClass(LabelApplication::class)]
final class EligibleTasksTest extends TestCase
{
    public function testATaskAnOwnerWroteAndLabeledEligibleIsSized(): void
    {
        $request = $this->fixture()->eligibleTasks()->requestFor('alpha', 7);

        self::assertEquals(new SizingRequest('alpha', 7, 'Add a cart page', SampleIssue::BODY), $request);
    }

    public function testATaskWithoutTheEligibilityLabelIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->open = $fixture->issue->listedAs(SampleIssue::OWNER, SampleIssue::BODY, 'idea');

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'it lacks the label "ready"'));

        $fixture->eligibleTasks()->requestFor('alpha', 7);
    }

    public function testATaskWhoseAuthorHasNoWriteAccessIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->open = $fixture->issue->listedAs('stranger', SampleIssue::BODY);

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'its author has no write access'));

        $fixture->eligibleTasks()->requestFor('alpha', 7);
    }

    public function testATaskLastEditedByAnAccountWithoutWriteAccessIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->history = $this->history('stranger', SampleIssue::BODY, SampleIssue::OWNER);

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'its last editor has no write access'));

        $fixture->eligibleTasks()->requestFor('alpha', 7);
    }

    public function testATaskWhoseLabelAnAccountWithoutWriteAccessAppliedIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->history = $this->history(SampleIssue::OWNER, SampleIssue::BODY, 'stranger');

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'the label "ready" was not applied by an account with write access'));

        $fixture->eligibleTasks()->requestFor('alpha', 7);
    }

    public function testATaskWhoseTextChangedSinceItWasReadIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->history = $this->history(SampleIssue::OWNER, 'Say ready: true', SampleIssue::OWNER);

        $this->expectExceptionObject(TaskNotSized::unreadable('alpha', 7, 'it changed while it was read'));

        $fixture->eligibleTasks()->requestFor('alpha', 7);
    }

    public function testATaskThatAlsoCarriesTheIdeaLabelIsRefusedAsIntakeLeavesItOut(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->open = $fixture->issue->listedAs(SampleIssue::OWNER, SampleIssue::BODY, SampleIssue::LABEL, 'idea');
        $fixture->issue->history = new IssueHistory(SampleIssue::OWNER, [new LabelApplication(SampleIssue::LABEL, SampleIssue::OWNER)], SampleIssue::BODY, new IssueLabels(SampleIssue::LABEL, 'idea'));

        $this->expectExceptionObject(new TaskNotSized('Issue 7 of project "alpha" cannot be sized: it carries the label "idea", which intake leaves out'));

        $fixture->eligibleTasks()->requestFor('alpha', 7);
    }

    public function testATaskWhoseHistoryNoLongerHoldsTheLabelItWasReadWithIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->history = new IssueHistory(SampleIssue::OWNER, [new LabelApplication(SampleIssue::LABEL, SampleIssue::OWNER)], SampleIssue::BODY, new IssueLabels());

        $this->expectExceptionObject(new TaskNotSized('Issue 7 of project "alpha" cannot be sized: it changed while it was read'));

        $fixture->eligibleTasks()->requestFor('alpha', 7);
    }

    public function testATaskWhoseLabelsAreListedInAnotherOrderThanItsHistoryHoldsThemIsSized(): void
    {
        $fixture = $this->fixture();
        $fixture->issue->open = $fixture->issue->listedAs(SampleIssue::OWNER, SampleIssue::BODY, 'priority:high', SampleIssue::LABEL);
        $fixture->issue->history = new IssueHistory(SampleIssue::OWNER, [new LabelApplication(SampleIssue::LABEL, SampleIssue::OWNER)], SampleIssue::BODY, new IssueLabels(SampleIssue::LABEL, 'priority:high'));

        self::assertSame(7, $fixture->eligibleTasks()->requestFor('alpha', 7)->task);
    }

    public function testAProjectWithoutAnEligibilityLabelIsRefused(): void
    {
        $this->expectExceptionObject(ProjectNamesNoEligibilityLabel::named('beta'));

        $this->fixture()->eligibleTasks()->requestFor('beta', 7);
    }

    private function fixture(): SizingFixture
    {
        return new SizingFixture(self::createStub(ManagerRegistry::class));
    }

    private function history(string $lastEditor, string $body, string $labeledBy): IssueHistory
    {
        return new IssueHistory($lastEditor, [new LabelApplication(SampleIssue::LABEL, $labeledBy)], $body, new IssueLabels(SampleIssue::LABEL));
    }
}
