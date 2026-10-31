<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use FilesystemIterator;
use Intakt\Gates\RequiredTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(RequiredTools::class)]
final class RequiredToolsTest extends TestCase
{
    private string|false $path;

    private string $cwd;

    private string $base;

    private string $root;

    private string $caller;

    protected function setUp(): void
    {
        $this->path = getenv('PATH');
        $this->cwd = getcwd() ?: '.';
        putenv('INTAKT_GITLEAKS');
        $this->base = sys_get_temp_dir().'/intakt-required-tools-'.bin2hex(random_bytes(6));
        $this->root = $this->base.'/workspace';
        $this->caller = $this->base.'/caller';
        mkdir($this->root, 0o700, true);
        mkdir($this->caller, 0o700, true);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        putenv(false === $this->path ? 'PATH' : 'PATH='.$this->path);
        putenv('INTAKT_GITLEAKS');
        $this->remove($this->base);
    }

    public function testEveryMissingToolOfTheSelectedGatesIsListedOnce(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        $missing = $this->tools()->missingFor(['audit', 'tests', 'secrets', 'secrets-history']);

        self::assertSame(
            array_map(RequiredTools::notFound(...), ['git', 'composer', 'sh', 'unshare', 'setsid', 'true', 'bash', 'jq', 'gitleaks', 'env']),
            $missing,
        );
    }

    public function testTheTestsGateNeedsBashAndJqForTheShowcaseAndReleaseLabelsScripts(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        $missing = $this->tools()->missingFor(['tests']);

        self::assertContains(RequiredTools::notFound('bash'), $missing);
        self::assertContains(RequiredTools::notFound('jq'), $missing);
    }

    public function testAToolOfAGateThatIsNotSelectedIsNotRequired(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        self::assertSame([RequiredTools::notFound('git')], $this->tools()->missingFor(['cs']));
    }

    public function testTheMessageNamesTheCommandAndTheRequirementsPage(): void
    {
        self::assertSame('gitleaks not found, see docs/architecture/requirements.md', RequiredTools::notFound('gitleaks'));
    }

    public function testAScannerNamedByTheOverrideIsLookedUpInsteadOfTheOneOnThePath(): void
    {
        putenv('INTAKT_GITLEAKS=/nonexistent/scanner');

        self::assertSame([RequiredTools::notFound('/nonexistent/scanner')], $this->tools()->missingFor(['secrets']));
    }

    public function testAScannerOverrideNamingAnotherRequiredToolIsListedOnce(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');
        putenv('INTAKT_GITLEAKS=env');

        $missing = $this->tools()->missingFor(['secrets', 'secrets-history']);

        self::assertSame(array_map(RequiredTools::notFound(...), ['git', 'env']), $missing);
    }

    public function testARelativeScannerPathIsFoundInTheWorkspaceRoot(): void
    {
        $this->install($this->root.'/scanner');
        putenv('INTAKT_GITLEAKS=./scanner');

        self::assertSame([], $this->tools()->missingFor(['secrets']));
    }

    public function testARelativeScannerPathMissingFromTheWorkspaceRootIsListed(): void
    {
        putenv('INTAKT_GITLEAKS=./scanner');

        self::assertSame([RequiredTools::notFound('./scanner')], $this->tools()->missingFor(['secrets']));
    }

    public function testAToolUnderARelativePathEntryOfTheWorkspaceRootIsFound(): void
    {
        $this->install($this->root.'/tools/workspace-tool');
        chdir($this->caller);
        $this->prependToPath('tools');
        putenv('INTAKT_GITLEAKS=workspace-tool');

        self::assertSame([], $this->tools()->missingFor(['secrets']));
    }

    public function testAToolOnlyUnderARelativePathEntryOfTheCallersDirectoryIsListed(): void
    {
        $this->install($this->caller.'/tools/caller-tool');
        chdir($this->caller);
        $this->prependToPath('tools');
        putenv('INTAKT_GITLEAKS=caller-tool');

        self::assertSame([RequiredTools::notFound('caller-tool')], $this->tools()->missingFor(['secrets']));
    }

    public function testToolsOnThePathAreFound(): void
    {
        self::assertSame([], $this->tools()->missingFor(['cs']));
    }

    private function tools(): RequiredTools
    {
        return new RequiredTools($this->root);
    }

    private function install(string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700, true);
        }

        file_put_contents($file, "#!/bin/sh\n");
        chmod($file, 0o700);
    }

    private function prependToPath(string $entry): void
    {
        putenv('PATH='.$entry.PATH_SEPARATOR.(false === $this->path ? '' : $this->path));
    }

    private function remove(string $directory): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
