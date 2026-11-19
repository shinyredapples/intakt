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
use ZipArchive;

// The dependency step's commands run by Composer itself on offline fixture projects, because the strings alone cannot show what an install holds.
#[CoversClass(PhpGate::class)]
#[UsesClass(DependencyStep::class)]
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

    public function testTheLockedInstallRefusesAPackageFromAPathRepositoryBecauseTheLockDoesNotPinItsContents(): void
    {
        new Filesystem()->dumpFile($this->directory.'/package/composer.json', '{"name":"fixture/local","version":"1.0.0"}');
        $this->project([
            'repositories' => [['type' => 'path', 'url' => 'package', 'options' => ['symlink' => false]], ['packagist.org' => false]],
            'require' => ['fixture/local' => '1.0.0'],
        ]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertStringContainsString('fixture/local', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
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

    public function testTheLockedInstallRefusesALocalArchiveWithoutAChecksumBecauseTheLockDoesNotPinItsContents(): void
    {
        $this->archive('first');
        $this->projectWithArchive([]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertStringContainsString('fixture/archive', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallAcceptsALocalArchiveWhoseChecksumIsInTheLockFile(): void
    {
        $this->archive('first');
        $this->projectWithArchive(['shasum' => (string) sha1_file($this->directory.'/package.zip')]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $this->mustRun($this->step->install);

        self::assertStringEqualsFile($this->directory.'/vendor/fixture/archive/contents.txt', 'first');
    }

    public function testALocalArchiveThatChangedAfterItsChecksumWasLockedFailsTheInstall(): void
    {
        $this->archive('first');
        $this->projectWithArchive(['shasum' => (string) sha1_file($this->directory.'/package.zip')]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->archive('second');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertFileDoesNotExist($this->directory.'/vendor/fixture/archive/contents.txt');
    }

    public function testTheLockedInstallRefusesAGitSourceLockedToABranchBecauseAdvancingTheBranchChangesTheInstall(): void
    {
        $this->gitPackage('first');
        $this->projectWithGitSource('fixture-branch');
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertStringContainsString('fixture/git', $install->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory.'/vendor');
    }

    public function testTheLockedInstallOfAGitSourceLockedToACommitStaysOnThatCommitWhenTheBranchAdvances(): void
    {
        $this->gitPackage('first');
        $this->projectWithGitSource(trim($this->mustRun('git -C package rev-parse HEAD')));
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $this->mustRun($this->step->install);

        self::assertStringEqualsFile($this->directory.'/vendor/fixture/git/contents.txt', 'first');
    }

    public function testTheLockedInstallRefusesABranchNamedLikeASha256CommitIdInASha1RepositoryAfterTheBranchAdvanced(): void
    {
        $branch = str_repeat('a', 64);
        $this->mustRun('git init -q --object-format=sha1 -b '.$branch.' package');
        $this->gitPackage('first');
        $this->projectWithGitSource($branch);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertStringContainsString('fixture/git was installed from source', $install->getErrorOutput());
    }

    public function testTheLockedInstallRefusesABranchNamedLikeASha1CommitIdInASha256RepositoryAfterTheBranchAdvanced(): void
    {
        $branch = str_repeat('b', 40);
        $this->mustRun('git init -q --object-format=sha256 -b '.$branch.' package');
        $this->gitPackage('first');
        $this->projectWithGitSource($branch);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertStringContainsString('fixture/git was installed from source', $install->getErrorOutput());
    }

    public function testTheLockedInstallOfASha256GitSourceLockedToACommitStaysOnThatCommitWhenTheBranchAdvances(): void
    {
        $this->mustRun('git init -q --object-format=sha256 -b fixture-branch package');
        $this->gitPackage('first');
        $this->projectWithGitSource(trim($this->mustRun('git -C package rev-parse HEAD')));
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');
        $this->gitPackage('second');

        $this->mustRun($this->step->install);

        self::assertStringEqualsFile($this->directory.'/vendor/fixture/git/contents.txt', 'first');
    }

    public function testTheLockedInstallComparesTheSourcesInAVendorFolderTheManifestRenamed(): void
    {
        $branch = str_repeat('a', 64);
        $this->mustRun('git init -q --object-format=sha1 -b '.$branch.' package');
        $this->gitPackage('first');
        $this->projectWithGitSource($branch, ['vendor-dir' => 'packages']);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $install = $this->runInProject($this->step->install);

        self::assertFalse($install->isSuccessful());
        self::assertStringContainsString('fixture/git was installed from source', $install->getErrorOutput());
    }

    public function testTheLockGuardRefusesARemoteArchiveOfABranchWithoutAChecksumBecauseTheBranchCanMove(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/main', 'reference' => 'main',
        ]]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertFalse($guard->isSuccessful());
        self::assertStringContainsString('fixture/remote', $guard->getErrorOutput());
    }

    public function testAPinnedSourceDoesNotExcuseARemoteArchiveOfABranchBecauseComposerInstallsTheArchive(): void
    {
        $this->lockFileWith([
            'name' => 'fixture/remote', 'version' => '1.0.0',
            'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/main'],
            'source' => ['type' => 'git', 'url' => 'https://github.com/fixture/remote.git', 'reference' => str_repeat('a', 40)],
        ]);

        $guard = $this->runInProject(PhpGateSteps::LOCK_GUARD);

        self::assertFalse($guard->isSuccessful());
        self::assertStringContainsString('fixture/remote', $guard->getErrorOutput());
    }

    public function testARemoteArchiveWhoseUrlOnlyMentionsItsCommitIdInTheQueryIsRefused(): void
    {
        $commit = str_repeat('a', 40);
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/main?pin=/'.$commit, 'reference' => $commit,
        ]]);

        self::assertFalse($this->runInProject(PhpGateSteps::LOCK_GUARD)->isSuccessful());
    }

    public function testARemoteArchiveOverPlainHttpWithoutAChecksumIsRefused(): void
    {
        $commit = str_repeat('a', 40);
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'http://api.github.com/repos/fixture/remote/zipball/'.$commit, 'reference' => $commit,
        ]]);

        self::assertFalse($this->runInProject(PhpGateSteps::LOCK_GUARD)->isSuccessful());
    }

    public function testTheLockGuardAcceptsARemoteArchiveWhoseUrlEndsInItsLockedCommitId(): void
    {
        $commit = str_repeat('a', 40);
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'https://api.github.com/repos/fixture/remote/zipball/'.$commit, 'reference' => $commit, 'shasum' => '',
        ]]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
    }

    public function testTheLockGuardAcceptsARemoteArchiveOfATagWhoseChecksumIsInTheLockFile(): void
    {
        $this->lockFileWith(['name' => 'fixture/remote', 'version' => '1.0.0', 'dist' => [
            'type' => 'zip', 'url' => 'https://codeload.github.com/fixture/remote/zip/v1.0.0', 'reference' => 'v1.0.0', 'shasum' => sha1('archive'),
        ]]);

        $this->mustRun(PhpGateSteps::LOCK_GUARD);
    }

    public function testTheLockedInstallAcceptsALockFileWithOnlyPinnedPackages(): void
    {
        $this->project([
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => ['name' => 'fixture/pinned', 'version' => '1.0.0', 'type' => 'metapackage']],
            ],
            'require' => ['fixture/pinned' => '1.0.0'],
        ]);
        $this->mustRun('composer update --no-interaction --no-plugins --no-scripts --no-install');

        $this->mustRun($this->step->install);

        self::assertFileExists($this->directory.'/vendor/composer/installed.php');
        self::assertFileDoesNotExist($this->directory.'/vendor/autoload.php');
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function project(array $manifest): void
    {
        $manifest = ['name' => 'fixture/root', 'repositories' => [['packagist.org' => false]], ...$manifest];
        new Filesystem()->dumpFile($this->directory.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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
        $this->project(['require' => [$package['name'] => '1.0.0']]);
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
            'GIT_AUTHOR_NAME' => 'Fixture',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_NAME' => 'Fixture',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
        ]);
        $process->run();

        return $process;
    }
}
