<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

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
        $sandbox = $this->branchWithAMergeCommit('Merge '.str_repeat('a', 40).' into '.str_repeat('b', 40));

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.$this->describe($sandbox, 'HEAD'));

        self::assertTrue($run->passed('commits'), $run->output());
    }

    public function testAMergeCommitWithTheSyntheticSubjectButAnotherShaFails(): void
    {
        $sandbox = $this->branchWithAMergeCommit('Merge '.str_repeat('a', 40).' into '.str_repeat('b', 40));
        $described = explode(':', $this->describe($sandbox, 'HEAD'));

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.str_repeat('c', 40).':'.$described[1].':'.$described[2]);

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testAMergeCommitWithOtherParentsThanGithubDescribesFails(): void
    {
        $sandbox = $this->branchWithAMergeCommit('Merge '.str_repeat('a', 40).' into '.str_repeat('b', 40));
        $described = explode(':', $this->describe($sandbox, 'HEAD'));

        $run = $sandbox->check('--only=commits', '--base=main', '--test-merge='.$described[0].':'.$described[2].':'.$described[1]);

        self::assertTrue($run->failed('commits'), $run->output());
    }

    public function testASyntheticLookingMergeWithoutAnyDescriptionFails(): void
    {
        $sandbox = $this->branchWithAMergeCommit('Merge '.str_repeat('a', 40).' into '.str_repeat('b', 40));

        self::assertTrue($sandbox->check('--only=commits', '--base=main')->failed('commits'));
    }

    private function describe(Sandbox $sandbox, string $merge): string
    {
        $parents = explode(' ', trim($sandbox->git('log', '--format=%P', '--max-count=1', $merge)->output));

        return trim($sandbox->git('rev-parse', $merge)->output).':'.$parents[0].':'.$parents[1];
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
