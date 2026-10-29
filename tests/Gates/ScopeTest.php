<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GitCall;
use Intakt\Gates\HardenedGitCall;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Scope::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGitCall::class)]
final class ScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function analyzedExecutables(): iterable
    {
        yield 'bin/check' => ['bin/check'];
        yield 'bin/instance' => ['bin/instance'];
        yield 'bin/probe' => ['bin/probe'];
    }

    #[DataProvider('analyzedExecutables')]
    public function testExtensionlessPhpExecutablesAreInScope(string $path): void
    {
        self::assertTrue($this->scope()->includes($path));
    }

    public function testOtherExtensionlessFilesAreOutOfScope(): void
    {
        self::assertFalse($this->scope()->includes('bin/other'));
    }

    public function testTheRepositoryExecutablesAreAmongAllPhpFiles(): void
    {
        $files = new Scope(dirname(__DIR__, 2), new ProcessRunner())->allPhpFiles();

        self::assertContains('bin/probe', $files);
        self::assertContains('bin/instance', $files);
    }

    public function testChangedPathsRunNoCleanFilterThatTheRepositoryConfigures(): void
    {
        $sandbox = Sandbox::empty();
        $marker = $sandbox->root.'/.git/clean-ran';
        $sandbox->startRepository();
        $sandbox->write('.gitattributes', "*.txt filter=evil\n");
        $sandbox->write('note.txt', "base\n");
        $sandbox->commitAll('Add a note');
        $sandbox->git('config', 'filter.evil.clean', 'touch '.$marker.'; cat');
        $sandbox->write('note.txt', "changed\n");

        $sandbox->run('git', 'diff', '--name-only', 'HEAD');
        self::assertFileExists($marker, 'the filter must run without hardening, or this test proves nothing');
        unlink($marker);

        $changed = new Scope($sandbox->root, new ProcessRunner())->changedPaths('HEAD');
        self::assertFileDoesNotExist($marker);
        self::assertSame(['note.txt'], $changed);
        $sandbox->remove();
    }

    public function testChangedPathsRunNoCleanFilterThatInfoAttributesNameAfterTheirCheck(): void
    {
        $sandbox = Sandbox::empty();
        $marker = $sandbox->root.'/.git/clean-ran';
        $sandbox->startRepository();
        $sandbox->write('note.txt', "base\n");
        $sandbox->commitAll('Add a note');
        $sandbox->git('config', 'filter.evil.clean', 'touch '.$marker.'; cat');
        $sandbox->write('note.txt', "changed\n");
        $path = (string) getenv('PATH');
        putenv('PATH='.$this->gitThatWritesInfoAttributesAfterEachCheck($sandbox).':'.$path);

        try {
            $changed = new Scope($sandbox->root, new ProcessRunner())->changedPaths('HEAD');
        } finally {
            putenv('PATH='.$path);
        }

        self::assertFileDoesNotExist($marker);
        self::assertSame(['note.txt'], $changed);
        $sandbox->remove();
    }

    public function testChangedPathsReadTheWorkingTreeEvenWhenTheRepositoryConfigPointsElsewhere(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->startRepository();
        $sandbox->write('src/Changed.php', "<?php\n");
        $sandbox->commitAll('Add a class');
        $sandbox->write('.git/elsewhere/src/Changed.php', "<?php\n");
        $sandbox->git('config', 'core.worktree', $sandbox->root.'/.git/elsewhere');
        $sandbox->write('src/Changed.php', "<?php\n\necho 'changed';\n");

        $changed = new Scope($sandbox->root, new ProcessRunner())->changedPaths('HEAD');

        self::assertSame(['src/Changed.php'], $changed);
        $sandbox->remove();
    }

    public function testChangedPathsIncludeAFileWhoseOnlyChangeIsItsExecuteMode(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->startRepository();
        $sandbox->write('src/Unchanged.php', "<?php\n");
        $sandbox->write('src/Executable.php', "<?php\n");
        $sandbox->commitAll('Add two classes');
        chmod($sandbox->root.'/src/Executable.php', 0o755);

        $changed = new Scope($sandbox->root, new ProcessRunner())->changedPaths('HEAD');

        self::assertSame(['src/Executable.php'], $changed);
        $sandbox->remove();
    }

    public function testChangedPathsIncludeAFileThatLostItsExecuteMode(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->startRepository();
        $sandbox->write('bin/tool', "#!/bin/sh\n");
        chmod($sandbox->root.'/bin/tool', 0o755);
        $sandbox->commitAll('Add a tool');
        chmod($sandbox->root.'/bin/tool', 0o644);

        $changed = new Scope($sandbox->root, new ProcessRunner())->changedPaths('HEAD');

        self::assertSame(['bin/tool'], $changed);
        $sandbox->remove();
    }

    // A git on the path that lets each info/attributes check pass and then writes the file for the call itself, which forces the order a racing writer needs.
    private function gitThatWritesInfoAttributesAfterEachCheck(Sandbox $sandbox): string
    {
        $git = trim($sandbox->run('sh', '-c', 'command -v git')->output);
        $attributes = $sandbox->root.'/.git/info/attributes';
        $sandbox->write('.git/racing/git', implode("\n", [
            '#!/bin/sh',
            'case " $* " in *" --git-path info/attributes "*) exec '.$git.' "$@";; esac',
            'printf "* filter=evil\n" > '.$attributes,
            $git.' "$@"',
            'status=$?',
            'rm -f '.$attributes,
            'exit $status',
            '',
        ]));
        chmod($sandbox->root.'/.git/racing/git', 0o755);

        return $sandbox->root.'/.git/racing';
    }

    private function scope(): Scope
    {
        return new Scope(sys_get_temp_dir(), new ProcessRunner());
    }
}
