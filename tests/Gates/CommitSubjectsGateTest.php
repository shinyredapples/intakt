<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CommitSubjectsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CommitSubjectsGate::class)]
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

        self::assertTrue($sandbox->check('--only=commits', '--base=main')->passed('commits'));
    }

    public function testTheGateFailsWhenThereIsNoBaseBranch(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('feat: work');

        self::assertTrue($sandbox->check('--only=commits', '--base=missing')->failed('commits'));
    }

    public function testANonConformingMergeCommitFails(): void
    {
        $sandbox = $this->branchWithAMergeCommit("Merge branch 'main' into change");

        $run = $sandbox->check('--only=commits', '--base=main');

        self::assertTrue($run->failed('commits'), $run->output());
        self::assertStringContainsString("Merge branch 'main' into change", $run->findingsOf('commits'));
    }

    public function testAConformingMergeCommitPasses(): void
    {
        $sandbox = $this->branchWithAMergeCommit('chore: merge main into change');

        self::assertTrue($sandbox->check('--only=commits', '--base=main')->passed('commits'));
    }

    public function testTheTestMergeGithubDescribesIsNotChecked(): void
    {
        $sandbox = $this->testMergeOnReportedBase();

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.$this->describe($sandbox));

        self::assertTrue($run->passed('commits'), $run->output());
    }

    public function testATestMergeOnANewerBaseTipThanTheEventReportsIsNotChecked(): void
    {
        $sandbox = $this->testMergeOnMovedBase();

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.$this->describe($sandbox));

        self::assertTrue($run->passed('commits'), $run->output());
    }

    public function testAMergeCommitWithTheSyntheticSubjectButAnotherShaFails(): void
    {
        $sandbox = $this->testMergeOnMovedBase();
        $described = explode(':', $this->describe($sandbox));

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.str_repeat('c', 40).':'.$described[1].':'.$described[2]);

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testAMergeCommitWithAnotherSecondParentThanTheHeadFails(): void
    {
        $sandbox = $this->testMergeOnMovedBase();
        $described = explode(':', $this->describe($sandbox));

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.$described[0].':'.$described[1].':'.str_repeat('c', 40));

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testAMergeCommitWhoseFirstParentIsNotOnTheBaseBranchFails(): void
    {
        $sandbox = $this->testMergeOffTheBase();

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.$this->describe($sandbox));

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testASyntheticLookingMergeWithoutAnyDescriptionFails(): void
    {
        $sandbox = $this->testMergeOnReportedBase();

        self::assertTrue($sandbox->check('--only=commits', '--base=main')->failed('commits'));
    }

    private function describe(Sandbox $sandbox): string
    {
        $parents = explode(' ', trim($sandbox->git('log', '--format=%P', '--max-count=1', 'HEAD')->output));

        return trim($sandbox->git('rev-parse', 'HEAD')->output).':'.trim($sandbox->git('rev-parse', 'main~1')->output).':'.$parents[1];
    }

    // Because main~1 plays the base the event reports, a merge on it is GitHub's test merge before main moved.
    private function testMergeOnReportedBase(): Sandbox
    {
        $sandbox = $this->changeBesideAMovedMain();
        $sandbox->git('switch', '--quiet', '--create', 'test-merge', 'main~1');

        return $this->mergeChange($sandbox);
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

        return $sandbox->check('--only=commits', '--base=main');
    }
}
