<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpGate;

use Closure;
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
        $git = ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];
        new Filesystem()->mkdir($other);

        try {
            Process::fromShellCommandline('git init -q -b main', $other, $git)->mustRun();
            new Filesystem()->dumpFile($other.'/unrelated', 'unrelated change');
            $this->project([]);

            $inherited = [
                'GIT_DIR' => $other.'/.git',
                'GIT_WORK_TREE' => $other,
                'COMPOSER_NO_DEV' => '1',
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'commit.gpgsign',
                'GIT_CONFIG_VALUE_0' => 'true',
            ];
            $noDev = null;
            $this->withInherited($inherited, function () use (&$noDev): void {
                $this->mustRun('git init -q -b main && git add -A && git commit -qm fixture');
                $noDev = $this->mustRun('php -r \'var_export(getenv("COMPOSER_NO_DEV"));\'');
            });

            self::assertSame('false', $noDev);
            self::assertDirectoryExists($this->directory.'/.git');
            self::assertNotSame(0, Process::fromShellCommandline('git rev-parse --verify --quiet HEAD', $other, $git)->run());
            self::assertSame('', Process::fromShellCommandline('git diff --cached --name-only', $other, $git)->mustRun()->getOutput());
        } finally {
            new Filesystem()->remove($other);
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

    private function runInProject(string $command): Process
    {
        // Every Composer and Git variable of whoever runs the test is removed, read at run time because a typed list has missed one each time: such a setting points the fixtures at another manifest, repository or index, changes which packages install or makes the fixture commits ask for a signature.
        $inherited = array_filter(
            array_map(strval(...), array_keys([...getenv(), ...$_ENV, ...$_SERVER])),
            static fn (string $name): bool => str_starts_with($name, 'COMPOSER') || str_starts_with($name, 'GIT_'),
        );
        $process = Process::fromShellCommandline($command, $this->directory, [
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
