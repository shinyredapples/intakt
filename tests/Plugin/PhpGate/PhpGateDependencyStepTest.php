<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpGate;

use Composer\InstalledVersions;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\PhpGate\Contract\PhpGateSteps;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use PHPUnit\Framework\Attributes\CoversClass;
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

    // Removed from the commands' environment, because a Composer setting of whoever runs the test would point the fixtures at another manifest or vendor folder, refuse the local package repositories or skip the scripts the tests expect.
    private const array NOT_INHERITED = ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false, 'COMPOSER_DISABLE_NETWORK' => false, 'COMPOSER_AUTH' => false, 'COMPOSER_SKIP_SCRIPTS' => false];

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

    public function testTheLockedInstallExitsWithComposersOwnCodeWhenComposerFailsWithoutTouchingTheLockFile(): void
    {
        $this->project([
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => ['name' => 'fixture/absent', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => './absent.zip']]],
            ],
            'require' => ['fixture/absent' => '1.0.0'],
        ]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $lock = (string) file_get_contents($this->directory.'/composer.lock');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertNotSame(1, $install->getExitCode());
        self::assertStringNotContainsString('composer.lock changed', $install->getErrorOutput());
        self::assertStringEqualsFile($this->directory.'/composer.lock', $lock);
    }

    public function testTheSplitFlowStillRunsTheAutoloadScriptsWhenWhoeverRunsTheTestsSkipsThemInTheirEnvironment(): void
    {
        $this->project(['scripts' => ['post-autoload-dump' => '@php -r "touch(\'autoload-event\');"']]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $saved = [$_ENV['COMPOSER_SKIP_SCRIPTS'] ?? null, $_SERVER['COMPOSER_SKIP_SCRIPTS'] ?? null, getenv('COMPOSER_SKIP_SCRIPTS')];
        $_ENV['COMPOSER_SKIP_SCRIPTS'] = $_SERVER['COMPOSER_SKIP_SCRIPTS'] = 'post-autoload-dump';
        putenv('COMPOSER_SKIP_SCRIPTS=post-autoload-dump');

        try {
            $this->mustRun($this->step->install);
            $this->mustRun($this->step->generate);
        } finally {
            [$env, $server, $process] = $saved;
            if (null === $env) { unset($_ENV['COMPOSER_SKIP_SCRIPTS']); } else { $_ENV['COMPOSER_SKIP_SCRIPTS'] = $env; }
            if (null === $server) { unset($_SERVER['COMPOSER_SKIP_SCRIPTS']); } else { $_SERVER['COMPOSER_SKIP_SCRIPTS'] = $server; }
            putenv(false === $process ? 'COMPOSER_SKIP_SCRIPTS' : 'COMPOSER_SKIP_SCRIPTS='.$process);
        }

        self::assertFileExists($this->directory.'/autoload-event');
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

    public function testTheSplitFlowRunsTheTreesAutoloadScriptsButNeverItsPackageEventScripts(): void
    {
        $this->projectRequiringAPackage(['scripts' => [
            'post-package-install' => '@php -r "touch(\'package-event\');"',
            'post-autoload-dump' => '@php -r "touch(\'autoload-event\');"',
        ]]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $this->mustRun($this->step->install);
        self::assertFileDoesNotExist($this->directory.'/autoload-event');
        $this->mustRun($this->step->generate);

        self::assertFileExists($this->directory.'/autoload-event');
        self::assertFileDoesNotExist($this->directory.'/package-event');
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

    private function runInProject(string $command): Process
    {
        $process = Process::fromShellCommandline($command, $this->directory, [
            ...self::OFFLINE,
            'COMPOSER_HOME' => $this->directory.'/.composer-home',
            'COMPOSER_CACHE_DIR' => $this->directory.'/.composer-cache',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            ...self::NOT_INHERITED,
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
