<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use FilesystemIterator;
use Intakt\Gates\RequiredTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

#[CoversClass(RequiredTools::class)]
final class RequiredToolsTest extends TestCase
{
    private string|false $path;

    private string|false $scanner;

    private string $cwd;

    private string $base;

    private string $root;

    private string $caller;

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

    public function testNoCommandIsRequiredWhenNoGateIsSelected(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        self::assertSame([], $this->tools()->missingFor([]));
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

    public function testAToolUnderTheCallersRelativePathEntryAndALaterAbsoluteEntryIsFound(): void
    {
        $this->install($this->caller.'/tools/shared-tool');
        $this->install($this->base.'/absolute/shared-tool');
        chdir($this->caller);
        putenv('PATH=tools'.PATH_SEPARATOR.$this->base.'/absolute'.PATH_SEPARATOR.(false === $this->path ? '' : $this->path));
        putenv('INTAKT_GITLEAKS=shared-tool');

        self::assertSame([], $this->tools()->missingFor(['secrets']));
    }

    public function testToolsOnThePathAreFound(): void
    {
        self::assertSame([], $this->tools()->missingFor(['cs']));
    }

    public function testAComposerOlderThanTheCooldownPolicyIsRefusedNamingTheVersionFound(): void
    {
        $this->installComposerReporting('2.10.4');

        self::assertSame(['composer 2.10.4 found, 2.11 or later is required for policy.cooldown, see '.RequiredTools::REQUIREMENTS], $this->tools()->missingFor(['audit']));
    }

    public function testAComposerWithTheCooldownPolicyIsAccepted(): void
    {
        $this->installComposerReporting('2.11.0');

        self::assertSame([], $this->tools()->missingFor(['audit']));
    }

    public function testAComposerThatReportsNoVersionIsRefused(): void
    {
        $this->install($this->caller.'/bin/composer');
        $this->prependToPath($this->caller.'/bin');

        self::assertSame(['composer reports no version, 2.11 or later is required for policy.cooldown, see '.RequiredTools::REQUIREMENTS], $this->tools()->missingFor(['audit']));
    }

    public function testAComposerWhoseVersionCannotBeQueriedIsRefused(): void
    {
        $this->installComposerReporting('2.11.0');
        $code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
            .'echo json_encode((new '.RequiredTools::class.'('.var_export($this->root, true).'))->missingFor(["audit"]));';
        $child = new Process([PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $code]);

        $child->run();

        self::assertSame(json_encode(['composer version could not be queried, 2.11 or later is required for policy.cooldown, see '.RequiredTools::REQUIREMENTS]), $child->getOutput(), $child->getErrorOutput());
    }

    public function testWithoutAPathAToolOnTheDefaultPathIsFound(): void
    {
        putenv('PATH');

        self::assertSame([], $this->tools()->missingFor(['cs']));
    }

    public function testWithoutAPathOnlyToolsAGateProcessCannotStartAreListed(): void
    {
        putenv('PATH');
        $unstartable = array_values(array_filter(['git', 'composer'], fn (string $tool): bool => !$this->gateStarts($tool)));

        $missing = $this->tools()->missingFor(['audit']);

        self::assertSame(array_map(RequiredTools::notFound(...), $unstartable), $missing);
    }

    public function testWithoutAPathAToolOffTheDefaultPathIsListed(): void
    {
        putenv('PATH');
        putenv('INTAKT_GITLEAKS=intakt-no-such-tool');

        self::assertSame([RequiredTools::notFound('intakt-no-such-tool')], $this->tools()->missingFor(['secrets']));
    }

    public function testWithoutAPathOrAWayToStartAProcessOnlyTheWorkspaceRootIsSearched(): void
    {
        $this->install($this->root.'/git');
        $code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; putenv("PATH");'
            .'echo json_encode((new '.RequiredTools::class.'('.var_export($this->root, true).'))->missingFor(["audit"]));';
        $child = new Process([PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $code]);

        $child->run();

        self::assertSame(json_encode([RequiredTools::notFound('composer')]), $child->getOutput(), $child->getErrorOutput());
    }

    protected function setUp(): void
    {
        $this->path = getenv('PATH');
        $this->scanner = getenv('INTAKT_GITLEAKS');
        $cwd = getcwd();
        $this->cwd = false === $cwd ? '.' : $cwd;
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
        putenv(false === $this->scanner ? 'INTAKT_GITLEAKS' : 'INTAKT_GITLEAKS='.$this->scanner);
        $this->remove($this->base);
    }

    private function tools(): RequiredTools
    {
        return new RequiredTools($this->root);
    }

    private function gateStarts(string $tool): bool
    {
        $gate = new Process([$tool, '--version'], $this->root);
        $gate->run();

        return $gate->isSuccessful();
    }

    private function install(string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700, true);
        }

        file_put_contents($file, "#!/bin/sh\n");
        chmod($file, 0o700);
    }

    private function installComposerReporting(string $version): void
    {
        $this->install($this->caller.'/bin/composer');
        file_put_contents($this->caller.'/bin/composer', "#!/bin/sh\necho 'Composer version ".$version." 2026-01-01 00:00:00'\n");
        $this->prependToPath($this->caller.'/bin');
    }

    private function prependToPath(string $entry): void
    {
        putenv('PATH='.$entry.PATH_SEPARATOR.(false === $this->path ? '' : $this->path));
    }

    private function remove(string $directory): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isDir()) {
                rmdir($file->getPathname());
            } elseif ($file instanceof SplFileInfo) {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}
