<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CommitSubjectsGate;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CommitSubjectsGate::class)]
#[UsesClass(Workspace::class)]
final class CommitSubjectsGateTest extends SandboxTestCase
{
    public function testConventionalSubjectsPass(): void
    {
        $run = $this->checkCommitsWith(['feat(order): place an order', 'fix: round half up', 'refactor(core)!: rename the bus', 'revert: undo the rename']);

        self::assertTrue($run->passed('commits'), $run->output());
    }

    public function testASubjectWithoutAConventionalTypeFails(): void
    {
        $run = $this->checkCommitsWith(['feat: fine', 'wip more things']);

        self::assertTrue($run->failed('commits'), $run->output());
        self::assertStringContainsString('wip more things', $run->findingsOf('commits'));
        self::assertStringNotContainsString('feat: fine', $run->findingsOf('commits'));
    }

    public function testAnUnknownTypeFails(): void
    {
        self::assertTrue($this->checkCommitsWith(['feature: add a thing'])->failed('commits'));
    }

    public function testAMissingSpaceAfterTheColonFails(): void
    {
        self::assertTrue($this->checkCommitsWith(['feat:add a thing'])->failed('commits'));
    }

    public function testASubjectOfOneHundredCharactersPasses(): void
    {
        $subject = 'feat: '.str_repeat('a', 94);

        self::assertTrue($this->checkCommitsWith([$subject])->passed('commits'));
    }

    public function testASubjectOfMoreThanOneHundredCharactersFails(): void
    {
        $subject = 'feat: '.str_repeat('a', 95);

        self::assertTrue($this->checkCommitsWith([$subject])->failed('commits'));
    }

    public function testCommitsAlreadyOnTheBaseBranchAreNotChecked(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('old habits');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->commitAll('feat: new work');

        self::assertTrue($sandbox->runGate(new CommitSubjectsGate())->passed('commits'));
    }

    public function testTheGateFailsWhenThereIsNoBaseBranch(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('feat: work');
        $sandbox->git('branch', '--move', 'trunk');

        $run = $sandbox->runGate(new CommitSubjectsGate());

        self::assertTrue($run->failed('commits'), $run->output());
        self::assertStringContainsString('no base branch found', $run->findingsOf('commits'));
    }

    public function testANonConformingMergeCommitFails(): void
    {
        $sandbox = $this->branchWithAMergeCommit("Merge branch 'main' into change");

        $run = $sandbox->runGate(new CommitSubjectsGate());

        self::assertTrue($run->failed('commits'), $run->output());
        self::assertStringContainsString("Merge branch 'main' into change", $run->findingsOf('commits'));
    }

    public function testAConformingMergeCommitPasses(): void
    {
        $sandbox = $this->branchWithAMergeCommit('chore: merge main into change');

        self::assertTrue($sandbox->runGate(new CommitSubjectsGate())->passed('commits'));
    }

    public function testTheTestMergeGithubDescribesIsNotChecked(): void
    {
        $sandbox = $this->testMergeOnReportedBase();

        $run = $sandbox->runGate(new CommitSubjectsGate($this->describe($sandbox)));

        self::assertTrue($run->passed('commits'), $run->output());
    }

    public function testATestMergeOnANewerBaseTipThanTheEventReportsIsNotChecked(): void
    {
        $sandbox = $this->testMergeOnMovedBase();

        $run = $sandbox->runGate(new CommitSubjectsGate($this->describe($sandbox)));

        self::assertTrue($run->passed('commits'), $run->output());
    }

    public function testTheBaseBranchCheckOfATestMergeIgnoresAnInheritedGitDirectory(): void
    {
        $sandbox = $this->testMergeOnMovedBase();

        $run = $sandbox->checkWith(['GIT_DIR' => '/nonexistent'], '--only=commits', '--base=main', '--test-merge='.$this->describe($sandbox));

        self::assertTrue($run->passed('commits'), $run->output());
    }

    public function testAMergeCommitWithTheSyntheticSubjectButAnotherShaFails(): void
    {
        $sandbox = $this->testMergeOnMovedBase();
        $described = explode(':', $this->describe($sandbox));

        $run = $sandbox->runGate(new CommitSubjectsGate(str_repeat('c', 40).':'.$described[1]));

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testAMergeCommitWithAnotherSecondParentThanTheHeadFails(): void
    {
        $sandbox = $this->testMergeOnMovedBase();
        $described = explode(':', $this->describe($sandbox));

        $run = $sandbox->runGate(new CommitSubjectsGate($described[0].':'.str_repeat('c', 40)));

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testTheOldThreeFieldDescriptionExemptsNothing(): void
    {
        $sandbox = $this->testMergeOnReportedBase();
        $described = explode(':', $this->describe($sandbox));
        $reportedBase = trim($sandbox->git('rev-parse', 'main~1')->output);

        $run = $sandbox->runGate(new CommitSubjectsGate($described[0].':'.$reportedBase.':'.$described[1]));

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testAMergeCommitWhoseFirstParentIsNotOnTheBaseBranchFails(): void
    {
        $sandbox = $this->testMergeOffTheBase();

        $run = $sandbox->runGate(new CommitSubjectsGate($this->describe($sandbox)));

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testASyntheticLookingMergeWithoutAnyDescriptionFails(): void
    {
        $sandbox = $this->testMergeOnReportedBase();

        self::assertTrue($sandbox->runGate(new CommitSubjectsGate())->failed('commits'));
    }

    // Because main~1 plays the base the event reports, a merge on it is GitHub's test merge before main moved.
    private function testMergeOnReportedBase(): Sandbox
    {
        $sandbox = $this->changeBesideAMovedMain();
        $sandbox->git('switch', '--quiet', '--create', 'test-merge', 'main~1');

        return $this->mergeChange($sandbox);
    }

    private function describe(Sandbox $sandbox): string
    {
        $parents = explode(' ', trim($sandbox->git('log', '--format=%P', '--max-count=1', 'HEAD')->output));

        return trim($sandbox->git('rev-parse', 'HEAD')->output).':'.$parents[1];
    }

    private function testMergeOnMovedBase(): Sandbox
    {
        $sandbox = $this->changeBesideAMovedMain();
        $sandbox->git('switch', '--quiet', '--create', 'test-merge', 'main');

        return $this->mergeChange($sandbox);
    }

    private function testMergeOffTheBase(): Sandbox
    {
        $sandbox = $this->changeBesideAMovedMain();
        $sandbox->git('switch', '--quiet', '--create', 'test-merge', 'main');
        $sandbox->write('side.txt', "side\n");
        $sandbox->commitAll('chore: side work');

        return $this->mergeChange($sandbox);
    }

    private function changeBesideAMovedMain(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('change.txt', "change\n");
        $sandbox->commitAll('feat: change');
        $sandbox->git('switch', '--quiet', 'main');
        $sandbox->write('main.txt', "main\n");
        $sandbox->commitAll('chore: advance main');
        $sandbox->write('main.txt', "main again\n");
        $sandbox->commitAll('chore: advance main again');

        return $sandbox;
    }

    private function mergeChange(Sandbox $sandbox): Sandbox
    {
        $sandbox->git('merge', '--quiet', '--no-ff', '--message', 'Merge '.str_repeat('a', 40).' into '.str_repeat('b', 40), 'change');

        return $sandbox;
    }

    private function branchWithAMergeCommit(string $message): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('change.txt', "change\n");
        $sandbox->commitAll('feat: change');
        $sandbox->git('switch', '--quiet', 'main');
        $sandbox->write('main.txt', "main\n");
        $sandbox->commitAll('chore: advance main');
        $sandbox->git('switch', '--quiet', 'change');
        $sandbox->git('merge', '--quiet', '--no-ff', '--message', $message, 'main');

        return $sandbox;
    }

    /**
     * @param list<string> $subjects
     */
    private function checkCommitsWith(array $subjects): CheckRun
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        foreach ($subjects as $subject) {
            $sandbox->commitAll($subject);
        }

        return $sandbox->runGate(new CommitSubjectsGate());
    }
}
