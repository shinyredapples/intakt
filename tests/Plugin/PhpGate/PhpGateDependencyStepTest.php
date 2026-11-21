<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpGate;

use Closure;
use Composer\InstalledVersions;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\PhpGate\Contract\PhpGateSteps;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The dependency step's commands run by Composer itself on offline fixture projects, because the strings alone cannot show what an install holds.
#[CoversClass(PhpGate::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(PhpGateSteps::class)]
final class PhpGateDependencyStepTest extends TestCase
{
    // Because the fixtures serve their own packages, a proxy nobody listens on turns any request to Packagist into a failed test.
    private const array OFFLINE = ['http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => '', 'NO_PROXY' => ''];

    private string $directory;

    private DependencyStep $step;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-dependency-step-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $step = new PhpGate()->dependencyStep(null);
        self::assertNotNull($step);
        $this->step = $step;
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheGenerationPointsTheRootPackageMetadataAtTheCheckedHeadNotAtTheHeadThatInstalled(): void
    {
        $this->project(['autoload' => ['classmap' => ['code/']]]);
        new Filesystem()->dumpFile($this->directory.'/code/Before.php', '<?php class Before {}');
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->mustRun('git init -q -b main && git add -A && git commit -qm before');
        $this->mustRun($this->step->install);
        new Filesystem()->rename($this->directory.'/code/Before.php', $this->directory.'/code/After.php');
        new Filesystem()->dumpFile($this->directory.'/code/After.php', '<?php class After {}');
        $this->mustRun('git add -A && git commit -qm after');

        $this->mustRun($this->step->generate);

        self::assertSame(['After', InstalledVersions::class], array_keys($this->required('vendor/composer/autoload_classmap.php')));
        $installed = $this->required('vendor/composer/installed.php');
        self::assertIsArray($installed['root'] ?? null);
        self::assertSame(trim($this->mustRun('git rev-parse HEAD')), $installed['root']['reference'] ?? null);
    }

    public function testTheLockedInstallRefusesAProjectWithoutALockFileInsteadOfResolvingOne(): void
    {
        $this->project([]);

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertFileDoesNotExist($this->directory.'/composer.lock');
    }

    public function testTheLockedInstallRefusesAManifestThatTurnsTheLockOffBecauseComposerThenIgnoresTheLockFile(): void
    {
        $package = ['name' => 'fixture/pkg', 'version' => '1.0.0', 'type' => 'metapackage'];
        $manifest = ['require' => ['fixture/pkg' => '*']];
        $this->project([...$manifest, 'repositories' => [['packagist.org' => false], ['type' => 'package', 'package' => $package]]]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->project([
            ...$manifest,
            'config' => ['lock' => false],
            'repositories' => [['packagist.org' => false], ['type' => 'package', 'package' => [...$package, 'version' => '2.0.0']]],
        ]);

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertStringContainsString('config.lock', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallRefusesAManifestThatSetsTheLockToNullBecauseOnlyAMissingKeyMeansTheDefault(): void
    {
        $this->projectRequiringAPackage(['config' => ['lock' => true]]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->projectRequiringAPackage(['config' => ['lock' => null]]);

        $install = $this->runInProject($this->step->install);

        self::assertSame(1, $install->getExitCode());
        self::assertStringContainsString('config.lock', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallFailsWhenComposerResolvesAnEmptyLockFileAnewAndRewritesIt(): void
    {
        $this->projectRequiringAPackage([]);
        new Filesystem()->dumpFile($this->directory.'/composer.lock', '{}');

        $install = $this->runInProject($this->step->install);

        self::assertSame(1, $install->getExitCode());
        self::assertStringContainsString('composer.lock changed during the install', $install->getErrorOutput());
    }

    public function testTheLockedInstallReportsTheRewrittenLockFileAlsoWhenComposerFailsAfterResolvingAnEmptyLockFile(): void
    {
        $this->project([
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => ['name' => 'fixture/absent', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => './absent.zip']]],
            ],
            'require' => ['fixture/absent' => '1.0.0'],
        ]);
        new Filesystem()->dumpFile($this->directory.'/composer.lock', '{}');

        $install = $this->runInProject($this->step->install);

        self::assertSame(1, $install->getExitCode());
        self::assertStringContainsString('composer.lock changed during the install', $install->getErrorOutput());
    }

    public function testTheLockedInstallReportsTheRewrittenLockFileAlsoWhenTheCallersShellExitsOnTheFirstFailingCommand(): void
    {
        $this->project([
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => ['name' => 'fixture/absent', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => './absent.zip']]],
            ],
            'require' => ['fixture/absent' => '1.0.0'],
        ]);
        new Filesystem()->dumpFile($this->directory.'/composer.lock', '{}');

        $install = $this->runInProject('set -e; '.PhpGateSteps::LOCKED_INSTALL);

        self::assertSame(1, $install->getExitCode());
        self::assertStringNotEqualsFile($this->directory.'/composer.lock', '{}');
        self::assertStringContainsString('composer.lock changed during the install', $install->getErrorOutput());
    }

    public function testTheLockedInstallExitsWithComposersOwnCodeWhenComposerFailsWithoutTouchingTheLockFile(): void
    {
        $this->project([
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => ['name' => 'fixture/absent', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://archive.invalid/absent.zip', 'shasum' => str_repeat('a', 40)]]],
            ],
            'require' => ['fixture/absent' => '1.0.0'],
        ]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $lock = (string) file_get_contents($this->directory.'/composer.lock');

        $install = $this->runInProject($this->step->install);
        new Filesystem()->remove($this->directory.'/vendor');
        $composer = $this->runInProject('composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader');

        self::assertFalse($composer->isSuccessful());
        self::assertSame($composer->getExitCode(), $install->getExitCode());
        self::assertStringNotContainsString('composer.lock changed', $install->getErrorOutput());
        self::assertStringEqualsFile($this->directory.'/composer.lock', $lock);
    }

    public function testTheSplitFlowStillRunsTheAutoloadScriptsWhenWhoeverRunsTheTestsSkipsThemInTheirEnvironment(): void
    {
        $this->project(['scripts' => ['post-autoload-dump' => '@php -r "touch(\'autoload-event\');"']]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->withInherited(['COMPOSER_SKIP_SCRIPTS' => 'post-autoload-dump'], function (): void {
            $this->mustRun($this->step->install);
            $this->mustRun($this->step->generate);
        });

        self::assertFileExists($this->directory.'/autoload-event');
    }

    public function testTheGenerationWritesNoPlatformCheckFileWhenTheManifestTurnsThePlatformCheckOff(): void
    {
        $this->project(['config' => ['platform-check' => false]]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->mustRun($this->step->install);

        $this->mustRun($this->step->generate);

        self::assertFileExists($this->directory.'/vendor/autoload.php');
        self::assertFileDoesNotExist($this->directory.'/vendor/composer/platform_check.php');
    }

    public function testTheFixtureGitCommandsStayInTheFixtureWhenTheCallerPointsGitAtAnotherRepository(): void
    {
        $other = $this->directory.'-other';
        $objects = $this->directory.'-objects';
        new Filesystem()->mkdir($other);

        try {
            $inherited = [
                'GIT_DIR' => $other.'/.git',
                'GIT_WORK_TREE' => $other,
                'GIT_OBJECT_DIRECTORY' => $objects,
                'COMPOSER_NO_DEV' => '1',
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'commit.gpgsign',
                'GIT_CONFIG_VALUE_0' => 'true',
            ];
            $noDev = null;
            $this->withInherited($inherited, function () use ($other, &$noDev): void {
                self::assertTrue($this->runInProject('git init -q -b main', $other)->isSuccessful());
                new Filesystem()->dumpFile($other.'/unrelated', 'unrelated change');
                $this->project([]);
                $this->mustRun('git init -q -b main && git add -A && git commit -qm fixture');
                $noDev = $this->mustRun('php -r \'var_export(getenv("COMPOSER_NO_DEV"));\'');
            });

            self::assertSame('false', $noDev);
            self::assertDirectoryExists($this->directory.'/.git');
            self::assertDirectoryDoesNotExist($objects);
            self::assertNotSame(0, $this->runInProject('git rev-parse --verify --quiet HEAD', $other)->getExitCode());
            self::assertSame('', $this->runInProject('git diff --cached --name-only', $other)->getOutput());
        } finally {
            new Filesystem()->remove([$other, $objects]);
        }
    }

    public function testTheLockedInstallInstallsTheLockedPackagesWithoutAnAutoloaderAndLeavesTheLockFileAsItWas(): void
    {
        $this->projectRequiringAPackage(['config' => ['lock' => true]]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $lock = (string) file_get_contents($this->directory.'/composer.lock');

        $this->mustRun($this->step->install);

        self::assertFileExists($this->directory.'/vendor/composer/installed.php');
        self::assertFileDoesNotExist($this->directory.'/vendor/autoload.php');
        self::assertStringEqualsFile($this->directory.'/composer.lock', $lock);
    }

    public function testTheSplitFlowRunsTheTreesAutoloadScriptsOnlyInTheGeneration(): void
    {
        $this->projectRequiringAPackage(['scripts' => ['post-autoload-dump' => '@php -r "touch(\'autoload-event\');"']]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $this->mustRun($this->step->install);
        self::assertFileDoesNotExist($this->directory.'/autoload-event');
        $this->mustRun($this->step->generate);

        self::assertFileExists($this->directory.'/autoload-event');
    }

    /**
     * @param array<string, mixed> $package
     */
    #[DataProvider('refusedPackages')]
    public function testTheLockedInstallRefusesAPackageWhoseLockEntryDoesNotPinItsContents(string $reason, array $package): void
    {
        $this->lockedProject([$package]);

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode(), $reason);
        self::assertSame(1, preg_match_all('/\R/u', $install->getErrorOutput()), $reason);
        self::assertStringContainsString('"fixture/pkg"', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function refusedPackages(): iterable
    {
        $commit = str_repeat('a', 40);
        $archive = ['type' => 'zip', 'url' => 'https://archive.invalid/pkg.zip', 'reference' => $commit, 'shasum' => $commit];
        $source = ['type' => 'git', 'url' => 'https://git.invalid/pkg.git', 'reference' => $commit];
        $pkg = ['name' => 'fixture/pkg', 'version' => '1.0.0'];

        yield 'a path package' => ['path', [...$pkg, 'dist' => ['type' => 'path', 'url' => '../pkg']]];
        yield 'a path package in mixed case with a remote address and a checksum' => ['path mixed', [...$pkg, 'dist' => [...$archive, 'type' => 'Path']]];
        yield 'a path package in capitals with a remote address and a checksum' => ['path caps', [...$pkg, 'dist' => [...$archive, 'type' => 'PATH']]];
        yield 'a path source in mixed case' => ['path source', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'type' => 'Path']]];
        yield 'an archive without a checksum' => ['no checksum', [...$pkg, 'dist' => [...$archive, 'shasum' => '']]];
        yield 'an archive with no checksum key and a url that names another commit' => ['other commit', [...$pkg, 'dist' => ['type' => 'zip', 'url' => 'https://archive.invalid/'.str_repeat('b', 40), 'reference' => $commit]]];
        yield 'an archive named by a commit id over http' => ['http commit', [...$pkg, 'dist' => ['type' => 'zip', 'url' => 'http://archive.invalid/'.$commit, 'reference' => $commit]]];
        yield 'a source reference that is a branch' => ['branch', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'reference' => 'main']]];
        yield 'a source reference that is a short commit id' => ['short', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'reference' => 'aaaaaaa']]];
        yield 'a source without a reference' => ['no reference', [...$pkg, 'source' => ['type' => 'git', 'url' => 'https://git.invalid/pkg.git']]];
        yield 'a checksum in capitals' => ['capitals', [...$pkg, 'dist' => [...$archive, 'shasum' => str_repeat('A', 40)]]];
        yield 'a checksum that is too short' => ['short sum', [...$pkg, 'dist' => [...$archive, 'shasum' => 'abc']]];
        yield 'archive mirrors' => ['dist mirrors', [...$pkg, 'dist' => [...$archive, 'mirrors' => [['url' => 'https://mirror.invalid/', 'preferred' => true]]]]];
        yield 'source mirrors' => ['source mirrors', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'mirrors' => []]]];
        yield 'a compress.zlib archive with a valid checksum' => ['zlib', [...$pkg, 'dist' => [...$archive, 'url' => 'compress.zlib:///srv/archives/pkg.zip']]];
        yield 'a relative archive with a valid checksum' => ['relative', [...$pkg, 'dist' => [...$archive, 'url' => './pkg.zip']]];
        yield 'an absolute archive path' => ['absolute', [...$pkg, 'dist' => [...$archive, 'url' => '/srv/archives/pkg.zip']]];
        yield 'a user@host:path archive' => ['scp archive', [...$pkg, 'dist' => [...$archive, 'url' => 'git@git.invalid:o/pkg.zip']]];
        yield 'a file archive' => ['file', [...$pkg, 'dist' => [...$archive, 'url' => 'file:///srv/archives/pkg.zip']]];
        yield 'an ssh archive' => ['ssh archive', [...$pkg, 'dist' => [...$archive, 'url' => 'ssh://git.invalid/pkg.zip']]];
        yield 'a local source path' => ['local source', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'url' => '/srv/repos/pkg.git']]];
        yield 'a file source' => ['file source', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'url' => 'file:///srv/repos/pkg.git']]];
        yield 'a source with an unknown scheme' => ['ftp source', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'url' => 'ftp://git.invalid/pkg.git']]];
        yield 'a user@host:path source that is not git' => ['scp hg', [...$pkg, 'dist' => $archive, 'source' => ['type' => 'hg', 'url' => 'hg@hg.invalid:o/pkg', 'reference' => $commit]]];
        yield 'a source url that starts with an option' => ['option', [...$pkg, 'dist' => $archive, 'source' => [...$source, 'url' => '-oProxyCommand=x@host:path']]];
        yield 'an entry without a url' => ['no url', [...$pkg, 'dist' => ['type' => 'zip', 'shasum' => $commit]]];
        yield 'a composer-plugin' => ['plugin', [...$pkg, 'type' => 'composer-plugin']];
        yield 'a composer-plugin in capitals' => ['plugin caps', [...$pkg, 'type' => 'COMPOSER-PLUGIN']];
        yield 'a composer-plugin in mixed case' => ['plugin mixed', [...$pkg, 'type' => 'Composer-Plugin']];
        yield 'a composer-installer' => ['installer', [...$pkg, 'type' => 'composer-installer']];
        yield 'a composer-installer in capitals' => ['installer caps', [...$pkg, 'type' => 'COMPOSER-INSTALLER']];
    }

    /**
     * @param array<string, mixed> $package
     */
    #[DataProvider('acceptedPackages')]
    public function testTheLockGuardAcceptsAPackageWhoseLockEntryPinsItsContents(array $package): void
    {
        $this->lockedProject([$package], ['require' => ['fixture/pkg' => '*']]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(0, $guard->getExitCode(), $guard->getErrorOutput());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function acceptedPackages(): iterable
    {
        $commit = str_repeat('a', 40);
        $pkg = ['name' => 'fixture/pkg', 'version' => '1.0.0'];

        yield 'a metapackage' => [[...$pkg, 'type' => 'metapackage']];
        yield 'an https archive ending in its own commit id' => [[...$pkg, 'dist' => ['type' => 'zip', 'url' => 'https://api.git.invalid/zipball/'.$commit, 'reference' => $commit], 'source' => ['type' => 'git', 'url' => 'https://git.invalid/pkg.git', 'reference' => $commit]]];
        yield 'an http archive with a checksum' => [[...$pkg, 'dist' => ['type' => 'zip', 'url' => 'http://archive.invalid/pkg.zip', 'shasum' => $commit]]];
        yield 'a git source over user@host:path' => [[...$pkg, 'dist' => ['type' => 'zip', 'url' => 'https://archive.invalid/pkg.zip', 'shasum' => $commit], 'source' => ['type' => 'git', 'url' => 'git@git.invalid:o/pkg.git', 'reference' => $commit]]];
        yield 'a Git source over user@host:path, as Composer lowercases the type' => [[...$pkg, 'source' => ['type' => 'Git', 'url' => 'git@git.invalid:o/pkg.git', 'reference' => $commit]]];
        yield 'a GIT source over user@host:path, as Composer lowercases the type' => [[...$pkg, 'source' => ['type' => 'GIT', 'url' => 'git@git.invalid:o/pkg.git', 'reference' => $commit]]];
        yield 'a git source over git+ssh' => [[...$pkg, 'source' => ['type' => 'git', 'url' => 'git+ssh://git.invalid/o/pkg.git', 'reference' => $commit]]];
        yield 'the symfony/runtime plugin' => [['name' => 'symfony/runtime', 'version' => '1.0.0', 'type' => 'composer-plugin']];
    }

    #[DataProvider('packageEventScripts')]
    public function testTheLockedInstallRefusesARootScriptForAPackageEventBecauseTheInstallWouldSkipIt(string $event): void
    {
        $this->projectRequiringAPackage(['scripts' => [$event => '@php -r "touch(\'package-event\');"']]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString($event, $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function packageEventScripts(): iterable
    {
        foreach (['pre-package-install', 'post-package-install', 'pre-package-update', 'post-package-update'] as $event) {
            yield $event => [$event];
        }
    }

    #[DataProvider('lineBreakingNames')]
    public function testARefusalStaysOneLineWhateverCharactersThePackageNameHolds(string $name): void
    {
        $this->lockedProject([['name' => $name, 'version' => '1.0.0', 'type' => 'composer-plugin']]);

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertSame(1, preg_match_all('/\R/u', $install->getErrorOutput()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineBreakingNames(): iterable
    {
        yield 'a newline' => ["fixture/a\nb"];
        yield 'a carriage return' => ["fixture/a\rb"];
        yield 'U+0085' => ["fixture/a\u{0085}b"];
        yield 'U+2028' => ["fixture/a\u{2028}b"];
        yield 'U+2029' => ["fixture/a\u{2029}b"];
    }

    public function testTheLockedInstallRefusesWithItsOwnMessageWhenComposerCannotReportTheVendorFolder(): void
    {
        $this->projectRequiringAPackage([]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject('export COMPOSER=absent.json; '.PhpGateSteps::LOCKED_INSTALL);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('vendor folder', $install->getErrorOutput());
        self::assertStringNotContainsString('installed.json', $install->getErrorOutput());
    }

    public function testTheLockedInstallRefusesAVendorFolderThatAlreadyHoldsAnInstall(): void
    {
        $this->projectRequiringAPackage([]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->mustRun($this->step->install);

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('already holds an install', $install->getErrorOutput());
    }

    public function testTheLockedInstallRefusesAVendorFolderWhoseNameEndsInANewlineAndHoldsAnInstall(): void
    {
        $this->projectRequiringAPackage(['config' => ['vendor-dir' => "dependencies\n"]]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts');
        self::assertFileExists($this->directory."/dependencies\n/composer/installed.json");
        self::assertDirectoryDoesNotExist($this->directory.'/dependencies');

        $install = $this->runInProject(PhpGateSteps::LOCKED_INSTALL);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('already holds an install', $install->getErrorOutput());
    }

    public function testTheLockGuardRefusesAnInstallInAVendorFolderWhoseNameStartsWithADash(): void
    {
        $this->lockedProject([], ['config' => ['vendor-dir' => "-dependencies\n"]]);
        new Filesystem()->dumpFile($this->directory."/-dependencies\n/composer/installed.json", '[]');

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('already holds an install', $guard->getErrorOutput());
    }

    public function testARefusedAddressIsNotShownBecauseItCanHoldCredentials(): void
    {
        $this->lockedProject([['name' => 'fixture/pkg', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'ftp://user:hidden-word@archive.invalid/pkg.zip', 'shasum' => str_repeat('a', 40)]]]);

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('"fixture/pkg" has a dist url', $install->getErrorOutput());
        self::assertStringNotContainsString('hidden-word', $install->getErrorOutput());
    }

    public function testTheLockedInstallRefusesAManifestThatIsNotValidJsonWithOneLine(): void
    {
        new Filesystem()->dumpFile($this->directory.'/composer.json', '{"name":');
        new Filesystem()->dumpFile($this->directory.'/composer.lock', '{"packages":[],"packages-dev":[]}');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertSame(1, preg_match_all('/\R/u', $install->getErrorOutput()));
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallRefusesAnUnreadableLockFileWithOneLine(): void
    {
        $this->projectRequiringAPackage([]);
        new Filesystem()->dumpFile($this->directory.'/composer.lock', '{"packages": ');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertSame(1, preg_match_all('/\R/u', $install->getErrorOutput()));
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @param array<string, mixed>       $manifest
     */
    private function lockedProject(array $packages, array $manifest = []): void
    {
        $this->project($manifest);
        new Filesystem()->dumpFile($this->directory.'/composer.lock', json_encode(['packages' => $packages, 'packages-dev' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function projectRequiringAPackage(array $manifest): void
    {
        $this->project([
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => ['name' => 'fixture/pinned', 'version' => '1.0.0', 'type' => 'metapackage']],
            ],
            'require' => ['fixture/pinned' => '1.0.0'],
            ...$manifest,
        ]);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function project(array $manifest): void
    {
        $manifest = ['name' => 'fixture/root', 'repositories' => [['packagist.org' => false]], ...$manifest];
        new Filesystem()->dumpFile($this->directory.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<mixed>
     */
    private function required(string $file): array
    {
        $contents = require $this->directory.'/'.$file;
        self::assertIsArray($contents);

        return $contents;
    }

    private function mustRun(string $command): string
    {
        $process = $this->runInProject($command);
        self::assertTrue($process->isSuccessful(), $command."\n".$process->getErrorOutput());

        return $process->getOutput();
    }

    /**
     * @param array<string, string> $variables
     */
    private function withInherited(array $variables, Closure $run): void
    {
        $saved = [];
        foreach ($variables as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }

        try {
            $run();
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                $this->restore($_ENV, $name, $env);
                $this->restore($_SERVER, $name, $server);
                putenv(false === $process ? $name : $name.'='.$process);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $variables
     */
    private function restore(array &$variables, string $name, mixed $value): void
    {
        if (null === $value) {
            unset($variables[$name]);

            return;
        }
        $variables[$name] = $value;
    }

    private function runInProject(string $command, ?string $directory = null): Process
    {
        // Every Composer and Git variable of whoever runs the test is removed, read at run time because a typed list has missed one each time: such a setting points the fixtures at another manifest, repository or index, changes which packages install or makes the fixture commits ask for a signature.
        $inherited = array_filter(
            array_map(strval(...), array_keys([...getenv(), ...$_ENV, ...$_SERVER])),
            static fn (string $name): bool => str_starts_with($name, 'COMPOSER') || str_starts_with($name, 'GIT_'),
        );
        $process = Process::fromShellCommandline($command, $directory ?? $this->directory, [
            ...array_fill_keys($inherited, false),
            ...self::OFFLINE,
            'COMPOSER_HOME' => $this->directory.'/.composer-home',
            'COMPOSER_CACHE_DIR' => $this->directory.'/.composer-cache',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Fixture',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_NAME' => 'Fixture',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
        ]);
        $process->run();

        return $process;
    }
}
