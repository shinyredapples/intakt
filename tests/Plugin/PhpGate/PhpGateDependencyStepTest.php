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
use ZipArchive;

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

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertFileDoesNotExist($this->directory.'/composer.lock');
    }

    public function testTheLockedInstallRefusesAPackageFromAPathRepositoryBecauseTheLockDoesNotPinItsContents(): void
    {
        new Filesystem()->dumpFile($this->directory.'/package/composer.json', '{"name":"fixture/local","version":"1.0.0"}');
        $this->project([
            'repositories' => [['type' => 'path', 'url' => 'package', 'options' => ['symlink' => false]], ['packagist.org' => false]],
            'require' => ['fixture/local' => '1.0.0'],
        ]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('fixture/local comes from a path repository', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallRefusesALocalArchiveWithoutAChecksumBecauseTheLockDoesNotPinItsContents(): void
    {
        $this->archive('first');
        $this->projectWithArchive([]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('fixture/archive', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallRefusesALocalArchiveEvenWithAChecksumBecauseItsBytesComeFromTheTree(): void
    {
        $this->archive('first');
        $this->projectWithArchive(['shasum' => (string) sha1_file($this->directory.'/package.zip')]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('fixture/archive comes from an archive at a local URL', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    #[DataProvider('localUrls')]
    public function testTheLockGuardRefusesAnArchiveAtALocalUrlEvenWithAChecksum(string $url): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => $url, 'shasum' => sha1('archive')]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote comes from an archive at a local URL', $guard->getErrorOutput());
    }

    #[DataProvider('localUrls')]
    public function testTheLockGuardRefusesAGitSourceAtALocalUrlEvenWithAFullCommitId(string $url): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => $url, 'reference' => str_repeat('a', 40)]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote comes from a source at a local URL', $guard->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localUrls(): iterable
    {
        yield 'file URL' => ['file:///packages/remote'];
        yield 'upper-case file URL' => ['FILE:///packages/remote'];
        yield 'absolute path' => ['/packages/remote'];
        yield 'relative path' => ['packages/remote'];
        yield 'parent path' => ['../remote'];
        yield 'compressed stream of an absolute path' => ['compress.zlib:///packages/remote.zip'];
        yield 'phar stream' => ['phar:///packages/remote.phar/package'];
        yield 'unknown scheme' => ['ftp://localhost/remote.zip'];
    }

    public function testTheLockGuardRefusesACompressedStreamArchiveOfAnAbsolutePathWithAValidChecksum(): void
    {
        $this->archive('first');
        $path = $this->directory.'/package.zip';
        $this->lockFileWith(['name' => 'fixture/archive', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'compress.zlib://'.$path, 'shasum' => (string) sha1_file($path)]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/archive comes from an archive at a local URL', $guard->getErrorOutput());
    }

    #[DataProvider('remoteArchiveUrls')]
    public function testTheLockGuardAcceptsAnArchiveWithAChecksumAtAnHttpOrHttpsUrl(string $url): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => $url, 'shasum' => sha1('archive')]]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function remoteArchiveUrls(): iterable
    {
        yield 'https' => ['https://localhost/remote.zip'];
        yield 'http' => ['http://localhost/remote.zip'];
    }

    #[DataProvider('remoteSourceUrls')]
    public function testTheLockGuardAcceptsASourceWithAFullCommitIdAtARemoteSchemeTheAllowlistNames(string $url): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => $url, 'reference' => str_repeat('a', 40)]]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function remoteSourceUrls(): iterable
    {
        foreach (['https', 'http', 'ssh', 'git', 'git+ssh', 'svn'] as $scheme) {
            yield $scheme => [$scheme.'://localhost/remote.git'];
        }
    }

    public function testTheLockGuardRefusesAnArchiveAtAGitOnlySchemeBecauseOnlyHttpAndHttpsAreRemoteForAnArchive(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'ssh://localhost/remote.zip', 'shasum' => sha1('archive')]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote comes from an archive at a local URL', $guard->getErrorOutput());
    }

    public function testTheLockGuardAcceptsAGitSourceAtAnScpLikeRemoteWithAFullCommitId(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'source' => [
            'type' => 'git', 'url' => 'git@github.com:fixture/remote.git', 'reference' => str_repeat('a', 40),
        ]]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
    }

    public function testTheLockedInstallRefusesAnArchiveWhoseUrlLooksScpLikeBecauseComposerReadsItFromTheTree(): void
    {
        $this->archive('first');
        new Filesystem()->rename($this->directory.'/package.zip', $this->directory.'/fixture@archive:package.zip');
        $this->project([
            'repositories' => [['packagist.org' => false], ['type' => 'package', 'package' => [
                'name' => 'fixture/archive', 'version' => '1.0.0',
                'dist' => ['type' => 'zip', 'url' => 'fixture@archive:package.zip', 'shasum' => (string) sha1_file($this->directory.'/fixture@archive:package.zip')],
            ]]],
            'require' => ['fixture/archive' => '1.0.0'],
        ]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('fixture/archive comes from an archive at a local URL', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockGuardRefusesANonGitSourceWhoseUrlLooksScpLike(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'source' => [
            'type' => 'hg', 'url' => 'fixture@source:remote', 'reference' => str_repeat('a', 40),
        ]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote comes from a source at a local URL', $guard->getErrorOutput());
    }

    #[DataProvider('unpinnedChecksums')]
    public function testTheLockGuardRefusesAChecksumThatIsNeitherEmptyNorFortyLowercaseHexCharacters(mixed $shasum): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [...$this->commitArchive(), 'shasum' => $shasum]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote has a checksum that is not 40 lowercase hex characters', $guard->getErrorOutput());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unpinnedChecksums(): iterable
    {
        yield 'falsy string' => ['0'];
        yield 'falsy number' => [0];
        yield 'upper-case hex' => [strtoupper(sha1('archive'))];
        yield 'one character short' => [substr(sha1('archive'), 1)];
        yield 'one character long' => [sha1('archive').'a'];
    }

    public function testTheLockGuardAcceptsAChecksumOfExactlyFortyLowercaseHexCharacters(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'https://codeload.github.com/fixture/remote/zip/v1.0.0', 'reference' => 'v1.0.0', 'shasum' => sha1('archive'),
        ]]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
    }

    public function testTheLockGuardRefusesAnArchiveWithMirrorsBecauseComposerMayDownloadFromAMirrorFirst(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            ...$this->commitArchive(), 'mirrors' => [['url' => 'https://mirror.example.invalid/%package%/%reference%.%type%', 'preferred' => true]],
        ]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote names mirrors', $guard->getErrorOutput());
    }

    public function testTheLockGuardRefusesASourceWithMirrorsBecauseComposerMayCloneFromAMirrorFirst(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'source' => [
            'type' => 'git', 'url' => 'https://github.com/fixture/remote.git', 'reference' => str_repeat('a', 40),
            'mirrors' => [['url' => 'https://mirror.example.invalid/%package%.git', 'preferred' => true]],
        ]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote names mirrors', $guard->getErrorOutput());
    }

    public function testTheLockGuardRefusesAComposerPluginBecauseTheLockedInstallSkipsWhatItDoesDuringTheInstall(): void
    {
        $this->lockFileWith(['name' => 'composer/installers', 'version' => '2.3.0', 'type' => 'composer-plugin', 'dist' => $this->commitArchive()]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('composer/installers is a Composer plugin', $guard->getErrorOutput());
    }

    public function testTheLockGuardRefusesALegacyComposerInstallerBecauseComposerInstallsItAsAPlugin(): void
    {
        $this->lockFileWith(['name' => 'fixture/installer', 'version' => '1.0.0', 'type' => 'composer-installer', 'dist' => $this->commitArchive()]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/installer is a Composer plugin', $guard->getErrorOutput());
    }

    public function testTheLockGuardNamesAPackageWhoseNameHoldsANewlineOnOneLine(): void
    {
        $this->lockFileWith(['name' => "fixture/local\nsecond line", 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => './package']]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertSame("The package named \"fixture\\/local\\nsecond line\" comes from a path repository, so composer.lock does not pin its contents.\n", $guard->getErrorOutput());
    }

    public function testTheLockGuardAcceptsTheSymfonyRuntimePluginBecauseTheGenerationRedoesItsWork(): void
    {
        $this->lockFileWith(['name' => 'symfony/runtime', 'version' => '8.0.0', 'type' => 'composer-plugin', 'dist' => $this->commitArchive()]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
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

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('config.lock', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallRefusesAManifestThatSetsTheLockToNullBecauseOnlyAMissingKeyMeansTheDefault(): void
    {
        $this->projectRequiringAPackage(['config' => ['lock' => true]]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->projectRequiringAPackage(['config' => ['lock' => null]]);

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
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
                ['type' => 'package', 'package' => ['name' => 'fixture/absent', 'version' => '1.0.0', 'dist' => [
                    'type' => 'zip', 'url' => 'https://localhost/absent.zip', 'shasum' => sha1('absent'),
                ]]],
            ],
            'require' => ['fixture/absent' => '1.0.0'],
        ]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $lock = (string) file_get_contents($this->directory.'/composer.lock');

        $install = $this->runInProject($this->step->install);

        self::assertSame(100, $install->getExitCode());
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

    #[DataProvider('packageEvents')]
    public function testTheLockedInstallRefusesATreeWithAPackageEventScriptBecauseTheSplitFlowNeverRunsIt(string $event): void
    {
        $this->projectRequiringAPackage(['scripts' => [$event => '@php -r "touch(\'package-event\');"']]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertSame(PhpGateSteps::REFUSED, $install->getExitCode());
        self::assertStringContainsString('composer.json defines a '.$event.' script', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function packageEvents(): iterable
    {
        yield 'pre-package-install' => ['pre-package-install'];
        yield 'post-package-install' => ['post-package-install'];
        yield 'pre-package-update' => ['pre-package-update'];
        yield 'post-package-update' => ['post-package-update'];
    }

    public function testTheLockGuardRefusesAGitSourceLockedToABranchBecauseAdvancingTheBranchChangesTheInstall(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'source' => [
            'type' => 'git', 'url' => 'https://github.com/fixture/remote.git', 'reference' => 'fixture-branch',
        ]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote has a source reference that is not a full commit id', $guard->getErrorOutput());
    }

    public function testTheLockGuardAcceptsARemoteGitSourceLockedToAFullCommitId(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'source' => [
            'type' => 'git', 'url' => 'https://github.com/fixture/remote.git', 'reference' => str_repeat('a', 64),
        ]]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
    }

    public function testTheSourceGuardAcceptsAGitSourceLockedToACommitWhoseBranchAdvanced(): void
    {
        $this->gitPackage('first');
        $this->projectWithGitSource(trim($this->mustRun('git -C package rev-parse HEAD')));
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $guard = $this->installThenCompareSources();

        self::assertTrue($guard->isSuccessful(), $guard->getErrorOutput());
        self::assertStringEqualsFile($this->directory.'/vendor/fixture/git/contents.txt', 'first');
    }

    public function testTheSourceGuardReadsThePackageCheckoutEvenWhenTheCallerPointsGitAtARepositoryOnTheLockedCommit(): void
    {
        $this->gitPackage('first');
        $this->projectWithGitSource(trim($this->mustRun('git -C package rev-parse HEAD')));
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->mustRun('composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader');
        $this->mustRun('git -C vendor/fixture/git commit -q --allow-empty -m unlocked');

        $guard = $this->runInProject('export GIT_DIR='.escapeshellarg($this->directory.'/package/.git').'; '.PhpGateSteps::SOURCE_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/git was installed from source', $guard->getErrorOutput());
    }

    public function testTheSourceGuardRefusesACheckoutWhoseGitFileLeadsToARepositoryOnTheLockedCommit(): void
    {
        $this->gitPackage('first');
        $this->projectWithGitSource(trim($this->mustRun('git -C package rev-parse HEAD')));
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->mustRun('composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader');
        new Filesystem()->remove($this->directory.'/vendor/fixture/git/.git');
        new Filesystem()->dumpFile($this->directory.'/vendor/fixture/git/.git', 'gitdir: '.$this->directory.'/package/.git');

        $guard = $this->runInProject(PhpGateSteps::SOURCE_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/git was installed from source', $guard->getErrorOutput());
    }

    public function testTheSourceGuardRefusesABranchNamedLikeASha256CommitIdInASha1RepositoryAfterTheBranchAdvanced(): void
    {
        $branch = str_repeat('a', 64);
        $this->mustRun('git init -q --object-format=sha1 -b '.$branch.' package');
        $this->gitPackage('first');
        $this->projectWithGitSource($branch);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $guard = $this->installThenCompareSources();

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/git was installed from source', $guard->getErrorOutput());
    }

    public function testTheSourceGuardRefusesABranchNamedLikeASha1CommitIdInASha256RepositoryAfterTheBranchAdvanced(): void
    {
        $branch = str_repeat('b', 40);
        $this->mustRun('git init -q --object-format=sha256 -b '.$branch.' package');
        $this->gitPackage('first');
        $this->projectWithGitSource($branch);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $guard = $this->installThenCompareSources();

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/git was installed from source', $guard->getErrorOutput());
    }

    public function testTheSourceGuardAcceptsASha256GitSourceLockedToACommitWhoseBranchAdvanced(): void
    {
        $this->mustRun('git init -q --object-format=sha256 -b fixture-branch package');
        $this->gitPackage('first');
        $this->projectWithGitSource(trim($this->mustRun('git -C package rev-parse HEAD')));
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $guard = $this->installThenCompareSources();

        self::assertTrue($guard->isSuccessful(), $guard->getErrorOutput());
        self::assertStringEqualsFile($this->directory.'/vendor/fixture/git/contents.txt', 'first');
    }

    public function testTheSourceGuardComparesTheSourcesInAVendorFolderTheManifestRenamed(): void
    {
        $branch = str_repeat('a', 64);
        $this->mustRun('git init -q --object-format=sha1 -b '.$branch.' package');
        $this->gitPackage('first');
        $this->projectWithGitSource($branch, ['vendor-dir' => 'packages']);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $guard = $this->installThenCompareSources();

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/git was installed from source', $guard->getErrorOutput());
    }

    public function testTheSourceGuardRefusesAnInstallThatLeftNoInstalledJson(): void
    {
        $this->projectRequiringAPackage([]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $guard = $this->runInProject(PhpGateSteps::SOURCE_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('installed.json', $guard->getErrorOutput());
    }

    public function testTheLockedInstallEndsWithTheSourceGuard(): void
    {
        self::assertStringEndsWith(' && '.PhpGateSteps::SOURCE_GUARD, PhpGateSteps::LOCKED_INSTALL);
    }

    public function testTheLockGuardRefusesARemoteArchiveOfABranchWithoutAChecksumBecauseTheBranchCanMove(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/main', 'reference' => 'main',
        ]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote comes from an archive without a checksum', $guard->getErrorOutput());
    }

    public function testAPinnedSourceDoesNotExcuseARemoteArchiveOfABranchBecauseComposerInstallsTheArchive(): void
    {
        $this->lockFileWith([
            'name' => 'fixture/remote', 'version' => '1.0.0',
            'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/main'],
            'source' => ['type' => 'git', 'url' => 'https://github.com/fixture/remote.git', 'reference' => str_repeat('a', 40)],
        ]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertSame(PhpGateSteps::REFUSED, $guard->getExitCode());
        self::assertStringContainsString('fixture/remote', $guard->getErrorOutput());
    }

    public function testARemoteArchiveWhoseUrlOnlyMentionsItsCommitIdInTheQueryIsRefused(): void
    {
        $commit = str_repeat('a', 40);
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/main?pin=/'.$commit, 'reference' => $commit,
        ]]);

        self::assertSame(PhpGateSteps::REFUSED, $this->runInProject(PhpGateSteps::LOCK_GUARD)->getExitCode());
    }

    public function testARemoteArchiveOverPlainHttpWithoutAChecksumIsRefused(): void
    {
        $commit = str_repeat('a', 40);
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'http://api.github.com/repos/fixture/remote/zipball/'.$commit, 'reference' => $commit,
        ]]);

        self::assertSame(PhpGateSteps::REFUSED, $this->runInProject(PhpGateSteps::LOCK_GUARD)->getExitCode());
    }

    public function testTheLockGuardAcceptsARemoteArchiveWhoseUrlEndsInItsLockedCommitIdWithAnEmptyChecksum(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => $this->commitArchive()]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
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
     * @return array<string, string>
     */
    private function commitArchive(): array
    {
        $commit = str_repeat('a', 40);

        return ['type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/'.$commit, 'reference' => $commit, 'shasum' => ''];
    }

    private function archive(string $contents): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->directory.'/package.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('contents.txt', $contents);
        $zip->close();
    }

    /**
     * @param array<string, string> $checksum
     */
    private function projectWithArchive(array $checksum): void
    {
        $this->project([
            'repositories' => [['packagist.org' => false], ['type' => 'package', 'package' => [
                'name' => 'fixture/archive', 'version' => '1.0.0',
                'dist' => ['type' => 'zip', 'url' => './package.zip', ...$checksum],
            ]]],
            'require' => ['fixture/archive' => '1.0.0'],
        ]);
    }

    private function gitPackage(string $contents): void
    {
        if (!is_dir($this->directory.'/package/.git')) {
            $this->mustRun('git init -q -b fixture-branch package');
        }
        new Filesystem()->dumpFile($this->directory.'/package/contents.txt', $contents);
        $this->mustRun('git -C package add contents.txt && git -C package commit -qm '.$contents);
    }

    /**
     * @param array<string, mixed> $package
     */
    private function lockFileWith(array $package): void
    {
        $name = $package['name'] ?? null;
        self::assertIsString($name);
        $this->project(['require' => [$name => '*']]);
        new Filesystem()->dumpFile(
            $this->directory.'/composer.lock',
            json_encode(['packages' => [$package], 'packages-dev' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<string, string> $config
     */
    private function projectWithGitSource(string $reference, array $config = []): void
    {
        $this->project([
            'config' => ['preferred-install' => 'source', ...$config],
            'repositories' => [['packagist.org' => false], ['type' => 'package', 'package' => [
                'name' => 'fixture/git', 'version' => '1.0.0',
                'source' => ['type' => 'git', 'url' => $this->directory.'/package', 'reference' => $reference],
            ]]],
            'require' => ['fixture/git' => '1.0.0'],
        ]);
    }

    // The lock guard refuses the fixture's local git URL, so Composer runs on its own here and the source guard is checked alone.
    private function installThenCompareSources(): Process
    {
        $this->mustRun('composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader');

        return $this->runInProject(PhpGateSteps::SOURCE_GUARD);
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
