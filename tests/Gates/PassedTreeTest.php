<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FullReport;
use Intakt\Gates\GitCall;
use Intakt\Gates\HardenedGitCall;
use Intakt\Gates\Options;
use Intakt\Gates\PassedTree;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use Intakt\Gates\TreeFingerprint;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(PassedTree::class)]
#[CoversClass(TreeFingerprint::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGitCall::class)]
#[UsesClass(Options::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(Scope::class)]
#[UsesClass(Workspace::class)]
final class PassedTreeTest extends SandboxTestCase
{
    private const array ENVIRONMENT = ['PATH' => '/usr/bin:/bin'];

    public function testASecondCheckOfAnIdenticalTreeReusesThePassedResult(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();

        $this->pass($root, $home);

        self::assertTrue($this->tree($root, $home)->hasPassed());
    }

    public function testATreeWithoutAPassIsNotReused(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAnEditedTrackedFileRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $root->write('README.md', "edited\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testANewUntrackedFileRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $root->write('notes.txt', "new\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAnIgnoredFileKeepsThePassedResult(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('.gitignore', "/cache/\n");
        $root->commitAll('chore: ignore the cache');
        $this->pass($root, $home);

        $root->write('cache/entry', "kept out of the tree\n");

        self::assertTrue($this->tree($root, $home)->hasPassed());
    }

    public function testAnEditStagedInTheIndexRunsTheCheckAgainAndLeavesTheIndexAlone(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);
        $root->write('README.md', "staged\n");
        $root->git('add', 'README.md');
        $before = $root->git('diff', '--cached', '--name-only')->output;

        self::assertFalse($this->tree($root, $home)->hasPassed());
        self::assertSame("README.md\n", $before);
        self::assertSame($before, $root->git('diff', '--cached', '--name-only')->output);
    }

    public function testTheChecksOwnReportAndRecordKeepThePassedResult(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $root->write(FullReport::FOLDER.'/report-0123456789abcdef.json', "{}\n");

        self::assertTrue($this->tree($root, $home)->hasPassed());
    }

    public function testAChangedComposerLockRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $home->write('composer.lock', "{\"packages\":[1]}\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAChangedFileUnderTheGateConfigurationRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $home->write('config/gates/src/Gate.php', "<?php // changed\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAChangedBinCheckRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $home->write('bin/check', "#!/usr/bin/env php\n<?php // changed\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testOtherGateOptionsRunTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home, '--only=dashes');

        self::assertFalse($this->tree($root, $home)->hasPassed());
        self::assertFalse($this->tree($root, $home, '--only=dashes', '--fast')->hasPassed());
        self::assertTrue($this->tree($root, $home, '--only=dashes')->hasPassed());
    }

    public function testAChangedEnvironmentVariableRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $tree = PassedTree::before(new TreeFingerprint($root->root, $home->root, Options::fromArguments([]), [...self::ENVIRONMENT, 'INTAKT_TEST_WORKERS' => '0']));

        self::assertFalse($tree->hasPassed());
    }

    public function testANewCommitWithTheSameTreeRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $root->git('commit', '--quiet', '--allow-empty', '--message', 'chore: same tree');

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testATreeWithoutGitIsNeverReused(): void
    {
        $root = $this->emptySandbox();
        $root->write('README.md', "base\n");
        $root->write(FullReport::FOLDER.'/.keep', '');
        $home = $this->home();

        self::assertFalse($this->tree($root, $home)->recordPass());
        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testALinkedVarCheckIsRefusedForRecords(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $elsewhere = $this->emptySandbox();
        rmdir($root->root.'/'.FullReport::FOLDER);
        $root->symlink(FullReport::FOLDER, $elsewhere->root);

        self::assertFalse($this->tree($root, $home)->recordPass());
        self::assertFileDoesNotExist($elsewhere->root.'/passed.json');
    }

    public function testARecordReadThroughALinkedVarCheckIsNotReused(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);
        $elsewhere = $this->emptySandbox();
        rename($root->root.'/'.PassedTree::RECORD, $elsewhere->root.'/passed.json');
        rmdir($root->root.'/'.FullReport::FOLDER);
        $root->symlink(FullReport::FOLDER, $elsewhere->root);

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testATreeEditedAfterTheFingerprintWasTakenRecordsNoPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $tree = $this->tree($root, $home);

        $root->write('README.md', "edited while the gates ran\n");

        self::assertFalse($tree->recordPass());
        self::assertFileDoesNotExist($root->root.'/'.PassedTree::RECORD);
    }

    public function testARecordOfAnotherFingerprintIsNotReused(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write(PassedTree::RECORD, '{"fingerprint":"'.str_repeat('0', 64).'"}'."\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    private function pass(Sandbox $root, Sandbox $home, string ...$arguments): void
    {
        self::assertTrue($this->tree($root, $home, ...$arguments)->recordPass());
    }

    private function tree(Sandbox $root, Sandbox $home, string ...$arguments): PassedTree
    {
        return PassedTree::before(new TreeFingerprint($root->root, $home->root, Options::fromArguments(array_values($arguments)), self::ENVIRONMENT));
    }

    /**
     * @return array{Sandbox, Sandbox}
     */
    private function checkedTreeAndHome(): array
    {
        $root = $this->emptySandbox();
        $root->startRepository();
        $root->write('README.md', "base\n");
        $root->commitAll('feat: start');
        mkdir($root->root.'/'.FullReport::FOLDER, 0o777, true);

        return [$root, $this->home()];
    }

    private function home(): Sandbox
    {
        $home = $this->emptySandbox();
        $home->write('bin/check', "#!/usr/bin/env php\n<?php\n");
        $home->write('config/gates/src/Gate.php', "<?php\n");
        $home->write('composer.lock', "{\"packages\":[]}\n");

        return $home;
    }
}
