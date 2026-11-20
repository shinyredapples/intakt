<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use Intakt\Gates\GitCall;
use Intakt\Gates\HardenedGitCall;
use Intakt\Gates\Mode;
use Intakt\Gates\PhpFilesGate;
use Intakt\Gates\PhpFilesToolGate;
use Intakt\Gates\ProcessQueue;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use Intakt\Gates\StaticAnalysisGate;
use Intakt\Gates\Workspace;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(StaticAnalysisGate::class)]
#[CoversClass(PhpFilesToolGate::class)]
#[CoversClass(Workspace::class)]
#[UsesClass(PhpFilesGate::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(Scope::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessQueue::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGitCall::class)]
final class StaticAnalysisChangedFilesSignalTest extends SandboxTestCase
{
    private const string RECORD = 'phpstan-environment';

    private string|false $changedFilesOnly = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->changedFilesOnly = getenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
    }

    #[Override]
    protected function tearDown(): void
    {
        putenv(false === $this->changedFilesOnly ? StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE : StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'='.$this->changedFilesOnly);
        parent::tearDown();
    }

    public function testAChangedFilesRunOverASubsetOfThePhpFilesSignalsIt(): void
    {
        $tree = $this->branchChanging('src/Report.php');

        self::assertSame('1', $this->signalSeenByPhpstan($tree, Mode::ChangedFilesOnly));
    }

    public function testAFullRunDoesNotSignalAChangedFilesRun(): void
    {
        $tree = $this->branchChanging('src/Report.php');

        self::assertSame('unset', $this->signalSeenByPhpstan($tree, Mode::Full));
    }

    public function testAFullRunRemovesASignalItsCallerExported(): void
    {
        $tree = $this->branchChanging('src/Report.php');
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'=1');

        self::assertSame('unset', $this->signalSeenByPhpstan($tree, Mode::Full));
    }

    public function testAChangedFilesRunThatChangesEveryPhpFileDoesNotSignalIt(): void
    {
        $tree = $this->branchChanging('src/Base.php');

        self::assertSame('unset', $this->signalSeenByPhpstan($tree, Mode::ChangedFilesOnly));
    }

    public function testAChangedFilesRunThatAnalyzesEveryPhpFileBecauseAGateDefinitionChangedDoesNotSignalIt(): void
    {
        $tree = $this->branchChanging('src/Report.php', 'config/gates/phpstan.neon');

        self::assertSame('unset', $this->signalSeenByPhpstan($tree, Mode::ChangedFilesOnly));
    }

    private function branchChanging(string ...$paths): Sandbox
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $tree->write('src/Base.php', "<?php\n");
        $tree->commitAll('chore: start');
        $tree->git('switch', '--quiet', '--create', 'change');

        foreach ($paths as $path) {
            $tree->write($path, "<?php\n\n// Changed.\n");
        }

        $tree->commitAll('feat: change');

        return $tree;
    }

    // phpstan of a separate home records what it was started with, so the test reads the environment the gate gave it
    private function signalSeenByPhpstan(Sandbox $tree, Mode $mode): string
    {
        $home = $this->emptySandbox();
        $home->write('vendor/bin/phpstan', "#!/bin/sh\nprintf '%s' \"\${".StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'-unset}" > '.self::RECORD."\n");
        chmod($home->root.'/vendor/bin/phpstan', 0o755);

        $result = new StaticAnalysisGate()->run(new Workspace($tree->root, $home->root, $mode, 'main'));

        self::assertTrue($result->isPassing(), $result->toJson());

        return (string) file_get_contents($tree->root.'/'.self::RECORD);
    }
}
