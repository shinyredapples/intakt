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
use Intakt\Gates\TreeWalk;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

#[CoversClass(PassedTree::class)]
#[CoversClass(TreeFingerprint::class)]
#[CoversClass(TreeWalk::class)]
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

    public function testATreeThatIgnoresVarStillRecordsAndReusesAPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('.gitignore', "/var/\n");
        $root->commitAll('chore: ignore var');

        $this->pass($root, $home);

        self::assertTrue($this->tree($root, $home)->hasPassed());
    }

    public function testAnEditedTrackedFileRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $root->write('README.md', "edited\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testATrackedFileEditedAfterTheFingerprintAndBeforeTheLookupRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);
        $tree = $this->tree($root, $home);

        $root->write('README.md', "edited\n");

        self::assertFalse($tree->hasPassed());
    }

    public function testANewUntrackedFileRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $root->write('notes.txt', "new\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAnEditedFileGitIgnoresRunsTheCheckAgain(): void
    {
        foreach (['vendor/autoload.php', '.env.test.local', 'phpunit.xml'] as $ignored) {
            [$root, $home] = $this->checkedTreeAndHome();
            $root->write('.gitignore', "/var/\n/.env.*.local\n/vendor/\n/phpunit.xml\n");
            $root->write('vendor/autoload.php', "<?php\n");
            $root->commitAll('chore: ignore the installed files');
            $this->pass($root, $home);

            $root->write($ignored, "<?php // edited\n");

            self::assertFalse($this->tree($root, $home)->hasPassed(), $ignored);
        }
    }

    public function testAFileLeftOutOnlyByGitInfoExcludeRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('.git/info/exclude', "notes.md\n");
        $this->pass($root, $home);

        $root->write('notes.md', "new\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
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

    public function testAStagedEditWhoseWorkingFileIsBackAsBeforeChangesTheFingerprint(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $fingerprint = $this->fingerprint($root, $home, '--fast');
        $before = $fingerprint->taken();

        $root->write('README.md', "staged\n");
        $root->git('add', 'README.md');
        $root->write('README.md', "base\n");

        self::assertNotNull($before);
        self::assertNotSame($before, $fingerprint->taken());
    }

    public function testTakingTheFingerprintStoresNoObjectInGit(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('draft.txt', "discarded draft\n");
        $object = trim($root->git('hash-object', 'draft.txt')->output);

        self::assertNotNull($this->fingerprint($root, $home)->taken());
        self::assertFalse($root->git('cat-file', '-e', $object)->succeeded());
    }

    public function testTheFilesTheCheckWritesKeepThePassedResult(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('.phpunit.cache/test-results', "{}\n");
        $root->write('config/reference.php', "<?php\n");
        $this->pass($root, $home);

        $root->write(FullReport::FOLDER.'/report-0123456789abcdef.json', "{}\n");
        $root->write('var/cache/test/container.php', "<?php\n");
        $root->write('.phpunit.cache/test-results', "{\"version\":2}\n");
        $root->write('config/reference.php', "<?php // dumped again\n");

        self::assertTrue($this->tree($root, $home)->hasPassed());
    }

    #[DataProvider('pathsTheCheckWritesThatTheGatesList')]
    public function testAnEditedFileTheCheckWouldWriteCountsOnceTheGatesListIt(string $path): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write($path, "clean\n");
        $root->git('add', '--force', $path);
        $root->commitAll('feat: track a file where the check writes');
        $this->pass($root, $home);

        $root->write($path, "edited\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsTheCheckWritesThatTheGatesList(): iterable
    {
        yield 'a tracked file under the ignored var/' => ['var/tracked.md'];
        yield 'a tracked file under the ignored .phpunit.cache/' => ['.phpunit.cache/notes.md'];
        yield 'a tracked config/reference.php' => ['config/reference.php'];
        yield 'a tracked config/schema.json' => ['config/schema.json'];
    }

    public function testAnUntrackedFileUnderAVarThatIsNotIgnoredCounts(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('.gitignore', '/'.FullReport::FOLDER."/\n");
        $root->commitAll('chore: ignore only the folder of the check');
        $root->write('var/notes.md', "clean\n");
        $this->pass($root, $home);

        $root->write('var/notes.md', "edited\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testATreeThatDoesNotIgnoreTheFolderOfTheCheckKeepsNoRecord(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('.gitignore', "/vendor/\n");
        $root->commitAll('chore: stop ignoring var');

        self::assertFalse($this->tree($root, $home)->recordPass());
        self::assertFileDoesNotExist($root->root.'/'.PassedTree::RECORD);
    }

    public function testAnEditedFileBehindALinkedFolderRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $shared = $this->emptySandbox();
        $shared->write('autoload.php', "<?php\n");
        $root->symlink('vendor', $shared->root);
        $this->pass($root, $home);

        $shared->write('autoload.php', "<?php // patched\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAnEditedTrackedFileWhoseFolderBecameALinkRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('docs/guide.md', "clean\n");
        $root->commitAll('docs: add a guide');
        $elsewhere = $this->emptySandbox();
        $elsewhere->write('guide.md', "clean\n");
        unlink($root->root.'/docs/guide.md');
        rmdir($root->root.'/docs');
        $root->symlink('docs', $elsewhere->root);
        $this->pass($root, $home);

        $elsewhere->write('guide.md', "edited\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAnEditedFileBehindALinkedFileRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $elsewhere = $this->emptySandbox();
        $elsewhere->write('notes.md', "clean\n");
        $root->symlink('notes.md', $elsewhere->root.'/notes.md');
        $this->pass($root, $home);

        $elsewhere->write('notes.md', "edited\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testALinkThatLeadsBackIntoItsOwnFolderGivesNoFingerprint(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('src/Kept.php', "<?php\n");
        $root->symlink('src/back', $root->root);

        self::assertNull($this->fingerprint($root, $home)->taken());
    }

    public function testTwoLinksToTheSameFolderStillGiveAFingerprint(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $shared = $this->emptySandbox();
        $shared->write('autoload.php', "<?php\n");
        $root->symlink('first', $shared->root);
        $root->symlink('second', $shared->root);

        self::assertNotNull($this->fingerprint($root, $home)->taken());
    }

    public function testATreeLeftAloneWhileTheGatesRanRecordsAPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('src/Kept.php', "<?php\n");
        $tree = $this->treeStartedInTheNextSecond($root, $home);

        self::assertTrue($tree->recordPass());
    }

    // Because moving a folder leaves the times of the files in it alone, only the folder itself tells that the gates may have read another one in its place.
    public function testAFolderMovedAwayAndBackWhileTheGatesRanRecordsNoPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('src/Kept.php', "<?php\n");
        $tree = $this->treeStartedInTheNextSecond($root, $home);

        rename($root->root.'/src', $root->root.'/aside');
        rename($root->root.'/aside', $root->root.'/src');

        self::assertFalse($tree->recordPass());
        self::assertFileDoesNotExist($root->root.'/'.PassedTree::RECORD);
    }

    // Because the walk skips the ignored var/, only the folders above a tracked file there tell that the gates may have read the tree while that folder was away.
    public function testAFolderAboveATrackedFileUnderAnIgnoredVarMovedAwayAndBackWhileTheGatesRanRecordsNoPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('var/notes/example.md', "clean\n");
        $root->git('add', '--force', 'var/notes/example.md');
        $root->commitAll('docs: track a note under var');
        $tree = $this->treeStartedInTheNextSecond($root, $home);

        rename($root->root.'/var/notes', $root->root.'/var/aside');
        rename($root->root.'/var/aside', $root->root.'/var/notes');

        self::assertFalse($tree->recordPass());
        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    // Because moving the folder a link points at leaves the link itself alone, only that folder tells that the gates may have read the tree while it was away.
    public function testTheFolderBehindALinkAboveATrackedFileUnderAnIgnoredVarMovedAwayAndBackWhileTheGatesRanRecordsNoPass(): void
    {
        [$root, $home, $target] = $this->trackedNoteUnderVarBehindALink();
        $tree = $this->treeStartedInTheNextSecond($root, $home);

        rename($target, $target.'-aside');
        rename($target.'-aside', $target);

        self::assertFalse($tree->recordPass());
        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testATrackedFileUnderAnIgnoredVarBehindALinkLeftAloneWhileTheGatesRanRecordsAPass(): void
    {
        [$root, $home] = $this->trackedNoteUnderVarBehindALink();
        $tree = $this->treeStartedInTheNextSecond($root, $home);

        self::assertTrue($tree->recordPass());
        self::assertTrue($this->tree($root, $home)->hasPassed());
    }

    public function testATrackedFileUnderAnIgnoredVarLeftAloneWhileTheGatesRanRecordsAPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('var/notes/example.md', "clean\n");
        $root->git('add', '--force', 'var/notes/example.md');
        $root->commitAll('docs: track a note under var');
        $tree = $this->treeStartedInTheNextSecond($root, $home);

        self::assertTrue($tree->recordPass());
        self::assertTrue($this->tree($root, $home)->hasPassed());
    }

    public function testAFileThatCameAndWentWhileTheGatesRanRecordsNoPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('src/Kept.php', "<?php\n");
        $tree = $this->treeStartedInTheNextSecond($root, $home);

        $root->write('src/Extra.php', "<?php\n");
        unlink($root->root.'/src/Extra.php');

        self::assertFalse($tree->recordPass());
        self::assertFileDoesNotExist($root->root.'/'.PassedTree::RECORD);
    }

    // Because reading a record of any size whole would exhaust the memory before a gate starts, a process with little memory still has to answer.
    public function testARecordFarLargerThanAFingerprintIsNotReusedAndNotReadWhole(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $record = fopen($root->root.'/'.PassedTree::RECORD, 'w');
        self::assertIsResource($record);
        ftruncate($record, 256 * 1024 * 1024);
        fclose($record);
        $home->write('answer.php', '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; echo '.PassedTree::class.'::before(new '.TreeFingerprint::class.'($argv[1], $argv[2], '.Options::class.'::fromArguments([]), []))->hasPassed() ? "reused" : "not reused";');

        $answer = $home->run('php', '-d', 'memory_limit=64M', $home->root.'/answer.php', $root->root, $home->root);

        self::assertSame('not reused', $answer->output, $answer->errors);
    }

    public function testAChangedComposerLockRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $home->write('composer.lock', "{\"packages\":[1]}\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAnEditedVendorFileInTheHomeOfBinCheckRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $home->write('vendor/bin/phpunit', "<?php\n");
        $this->pass($root, $home);

        $home->write('vendor/bin/phpunit', "<?php // patched\n");

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

    public function testAskingForReuseKeepsThePassOfARunThatDidNotAsk(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home, '--only=dashes');

        self::assertTrue($this->tree($root, $home, '--only=dashes', '--reuse')->hasPassed());
    }

    public function testAChangedEnvironmentVariableRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $tree = PassedTree::before(new TreeFingerprint($root->root, $home->root, Options::fromArguments([]), [...self::ENVIRONMENT, 'INTAKT_TEST_WORKERS' => '0']), $this->settledClock());

        self::assertFalse($tree->hasPassed());
    }

    public function testANewCommitWithTheSameTreeRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        $root->git('commit', '--quiet', '--allow-empty', '--message', 'chore: same tree');

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAHistoryMadeShallowAfterThePassRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $this->pass($root, $home);

        file_put_contents($root->root.'/.git/shallow', trim($root->git('rev-parse', 'HEAD')->output)."\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testADeepenedShallowHistoryRunsTheCheckAgain(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        file_put_contents($root->root.'/.git/shallow', trim($root->git('rev-parse', 'HEAD')->output)."\n");
        $this->pass($root, $home);

        unlink($root->root.'/.git/shallow');

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    public function testAnUnchangedShallowHistoryStillReusesThePass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        file_put_contents($root->root.'/.git/shallow', trim($root->git('rev-parse', 'HEAD')->output)."\n");
        $this->pass($root, $home);

        self::assertTrue($this->tree($root, $home)->hasPassed());
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

    public function testAnUnreadableFileGivesNoFingerprint(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('secret.txt', "unreadable\n");
        chmod($root->root.'/secret.txt', 0o000);

        try {
            self::assertNull($this->fingerprint($root, $home)->taken());
            self::assertFalse($this->tree($root, $home)->recordPass());
        } finally {
            chmod($root->root.'/secret.txt', 0o644);
        }
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

    // Because the gates may have read the edited bytes, a file back as it was by the end of the run still records nothing.
    public function testAFileEditedAndRestoredWhileTheGatesRanRecordsNoPass(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $tree = PassedTree::before($this->fingerprint($root, $home), new MockClock());

        $root->write('README.md', "edited while the gates ran\n");
        $root->write('README.md', "base\n");

        self::assertFalse($tree->recordPass());
        self::assertFileDoesNotExist($root->root.'/'.PassedTree::RECORD);
    }

    public function testARecordOfAnotherFingerprintIsNotReused(): void
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write(PassedTree::RECORD, '{"fingerprint":"'.str_repeat('0', 64).'"}'."\n");

        self::assertFalse($this->tree($root, $home)->hasPassed());
    }

    /**
     * @return array{Sandbox, Sandbox}
     */
    private function checkedTreeAndHome(): array
    {
        $root = $this->emptySandbox();
        $root->startRepository();
        $root->write('README.md', "base\n");
        $root->write('.gitignore', "/var/\n/.phpunit.cache/\n/config/reference.php\n/config/schema.json\n");
        $root->commitAll('feat: start');
        mkdir($root->root.'/'.FullReport::FOLDER, 0o777, true);

        return [$root, $this->home()];
    }

    /**
     * @return array{Sandbox, Sandbox, string}
     */
    private function trackedNoteUnderVarBehindALink(): array
    {
        [$root, $home] = $this->checkedTreeAndHome();
        $root->write('var/notes/example.md', "clean\n");
        $root->git('add', '--force', 'var/notes/example.md');
        $root->commitAll('docs: track a note under var');
        $target = $this->emptySandbox()->root.'/notes';
        rename($root->root.'/var/notes', $target);
        $root->symlink('var/notes', $target);

        return [$root, $home, $target];
    }

    private function pass(Sandbox $root, Sandbox $home, string ...$arguments): void
    {
        self::assertTrue($this->tree($root, $home, ...$arguments)->recordPass());
    }

    private function tree(Sandbox $root, Sandbox $home, string ...$arguments): PassedTree
    {
        return PassedTree::before($this->fingerprint($root, $home, ...$arguments), $this->settledClock());
    }

    private function fingerprint(Sandbox $root, Sandbox $home, string ...$arguments): TreeFingerprint
    {
        return new TreeFingerprint($root->root, $home->root, Options::fromArguments(array_values($arguments)), self::ENVIRONMENT);
    }

    // Because a change made while the gates ran shows only against files that were settled before, the run starts on the real clock, in the second after the last write.
    private function treeStartedInTheNextSecond(Sandbox $root, Sandbox $home): PassedTree
    {
        $clock = new NativeClock();
        $clock->sleep(1.02 - (float) $clock->now()->format('0.u'));

        return PassedTree::before($this->fingerprint($root, $home), $clock);
    }

    // Because a file written in the second a run starts records no pass, the tests that want one start their run an hour after their files were written.
    private function settledClock(): MockClock
    {
        return new MockClock('+1 hour');
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
