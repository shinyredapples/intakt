<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

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

    private function scope(): Scope
    {
        return new Scope(sys_get_temp_dir(), new ProcessRunner());
    }
}
