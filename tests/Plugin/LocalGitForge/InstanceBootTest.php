<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Contract\RepositoryNotFound;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\BootHead\InstanceBoot;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(InstanceBoot::class)]
#[UsesClass(BootAt::class)]
#[UsesClass(BootOutcome::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(FileContent::class)]
#[UsesClass(FileNotFoundAtHead::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(RepositoryNotFound::class)]
final class InstanceBootTest extends TestCase
{
    // Reports what the commit it sits in holds, so a test can tell which commit and which harness booted.
    private const string HARNESS = <<<'PHP'
        <?php
        $root = dirname(__DIR__);
        echo "server log\n";
        $status = match (true) {
            is_file($root.'/broken') => ['status' => 'failed', 'reason' => trim(file_get_contents($root.'/broken'))],
            !is_file($root.'/vendor/autoload.php') => ['status' => 'failed', 'reason' => 'no autoloader'],
            default => ['status' => 'ready'],
        };
        file_put_contents('php://fd/3', json_encode($status)."\n");
        exit('ready' === $status['status'] ? 0 : 1);
        PHP;

    private const string ALWAYS_READY = <<<'PHP'
        <?php
        file_put_contents('php://fd/3', '{"status":"ready"}'."\n");
        PHP;

    private const string FAILING_COMPOSER = "#!/bin/sh\necho 'the lock file does not match' >&2\nexit 1\n";

    // Not shaped like a real token, so the secret scan has nothing to find, while the plugin's report still cannot hold it by chance.
    private const string PROBE_TOKEN = 'probe-token-of-the-booting-install';

    private TemporaryRepository $repository;
    private CommitId $main;

    public function testAHeadThatBootsAfterComposerInstallIsReportedAsBooted(): void
    {
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Booted, $outcome->state);
        self::assertSame('', $outcome->reason);
    }

    public function testAHeadThatDoesNotBootIsReportedAsFailedWithTheReasonOfTheHarness(): void
    {
        $head = $this->repository->commitFile('broken', "Unrecognized option \"nope\"\n");

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertSame('Unrecognized option "nope"', $outcome->reason);
    }

    public function testAHeadIsBootedWithMainsHarnessRatherThanItsOwn(): void
    {
        $this->repository->commitFile('bin/instance', self::ALWAYS_READY);
        $head = $this->repository->commitFile('broken', "the kernel does not boot\n");

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertSame('the kernel does not boot', $outcome->reason);
    }

    public function testAHeadWhoseHarnessIsALinkOutsideIsBootedWithMainsHarnessAndLeavesTheLinkedFileAlone(): void
    {
        $outside = $this->repository->path.'-outside';
        file_put_contents($outside, "kept\n");
        unlink($this->repository->path.'/bin/instance');
        symlink($outside, $this->repository->path.'/bin/instance');
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Booted, $outcome->state);
        self::assertSame("kept\n", file_get_contents($outside));
        unlink($outside);
    }

    public function testAHeadWhoseBinIsALinkFailsWithoutWritingThroughIt(): void
    {
        $outside = $this->repository->path.'-bin';
        mkdir($outside);
        $this->repository->removeFile('bin/instance');
        rmdir($this->repository->path.'/bin');
        symlink($outside, $this->repository->path.'/bin');
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertStringContainsString('bin that is not a plain folder', $outcome->reason);
        self::assertSame([], array_values(array_diff((array) scandir($outside), ['.', '..'])));
        rmdir($outside);
    }

    public function testAHeadWhoseComposerInstallFailsIsReportedAsFailedWithComposersMessage(): void
    {
        $head = $this->repository->commitFile('composer.json', '{"name": ');

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertStringStartsWith('composer install failed: ', $outcome->reason);
        self::assertStringContainsString('composer.json', $outcome->reason);
    }

    public function testAMissingComposerIsACrashOfTheBootStepNotAFailureOfTheHead(): void
    {
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $outcome = $this->bootWithoutComposerAnd($head, []);

        self::assertSame(BootState::Crashed, $outcome->state, $outcome->reason);
        self::assertStringStartsWith('composer could not run: ', $outcome->reason);
    }

    public function testAComposerThatCannotBeExecutedIsACrashOfTheBootStepNotAFailureOfTheHead(): void
    {
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $outcome = $this->bootWithoutComposerAnd($head, ['composer' => [0o644, self::FAILING_COMPOSER]]);

        self::assertSame(BootState::Crashed, $outcome->state, $outcome->reason);
        self::assertStringStartsWith('composer could not run: ', $outcome->reason);
    }

    public function testAComposerThatRunsAndExitsWithAnErrorIsAFailureOfTheHead(): void
    {
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $outcome = $this->bootWithoutComposerAnd($head, ['composer' => [0o755, self::FAILING_COMPOSER]]);

        self::assertSame(BootState::Failed, $outcome->state, $outcome->reason);
        self::assertSame('composer install failed: the lock file does not match', $outcome->reason);
    }

    public function testAHarnessThatHangsPastItsTimeLimitIsReportedAsFailedWithTheLimit(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\nsleep(30);\n");

        $outcome = new InstanceBoot(bootSeconds: 1)->of($this->at($harness), new CommitId($harness));

        self::assertSame(BootState::Failed, $outcome->state, $outcome->reason);
        self::assertSame('bin/instance passed its time limit of 1 seconds.', $outcome->reason);
    }

    public function testAComposerInstallThatHangsPastItsTimeLimitIsReportedAsFailedWithTheLimit(): void
    {
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $outcome = $this->bootWithoutComposerAnd($head, ['composer' => [0o755, "#!/bin/sh\nexec sleep 30\n"]], new InstanceBoot(installSeconds: 1));

        self::assertSame(BootState::Failed, $outcome->state, $outcome->reason);
        self::assertSame('composer install passed its time limit of 1 seconds.', $outcome->reason);
    }

    public function testAComposerPluginOfTheHeadDoesNotSeeASecretOfTheBootingInstall(): void
    {
        $seen = $this->repository->path.'-plugin-saw';
        $head = $this->commitPlugin("file_put_contents('".$seen."', var_export(getenv('INTAKT_TEST_SECRET'), true));");
        $_ENV['INTAKT_TEST_SECRET'] = 'from-dotenv';
        putenv('INTAKT_TEST_SECRET=from-environment');

        try {
            $outcome = $this->bootWithMainsHarness($head);
        } finally {
            unset($_ENV['INTAKT_TEST_SECRET']);
            putenv('INTAKT_TEST_SECRET');
        }

        self::assertSame(BootState::Booted, $outcome->state);
        self::assertSame('false', file_get_contents($seen));
        unlink($seen);
    }

    public function testAComposerPluginOfTheHeadDoesNotSeeTheComposerAuthTokenOfTheBootingInstallsComposerHome(): void
    {
        $composerHome = $this->repository->path.'-composer-home';
        $this->writeComposerAuth($composerHome);

        $saw = $this->githubOauthAPluginSeesWith(['COMPOSER_HOME' => $composerHome]);

        self::assertStringNotContainsString(self::PROBE_TOKEN, $saw, 'the plugin saw: '.$saw);
    }

    public function testAComposerPluginOfTheHeadDoesNotSeeTheComposerAuthTokenUnderTheBootingInstallsHome(): void
    {
        $home = $this->repository->path.'-home';
        $this->writeComposerAuth($home.'/.config/composer');

        $saw = $this->githubOauthAPluginSeesWith(['HOME' => $home, 'COMPOSER_HOME' => false, 'XDG_CONFIG_HOME' => false]);

        self::assertStringNotContainsString(self::PROBE_TOKEN, $saw, 'the plugin saw: '.$saw);
    }

    public function testTheBootRunsInAScratchHomeThatIsRemovedWithTheSnapshot(): void
    {
        $seen = $this->repository->path.'-plugin-saw';
        $head = $this->commitPlugin("file_put_contents('".$seen."', json_encode([getenv('HOME'), getenv('COMPOSER_HOME')]));");

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Booted, $outcome->state, $outcome->reason);
        [$home, $composerHome] = (array) json_decode((string) file_get_contents($seen), true);
        unlink($seen);
        self::assertIsString($home);
        self::assertSame($home, $composerHome);
        self::assertNotSame(getenv('HOME'), $home);
        self::assertDirectoryDoesNotExist($home);
    }

    public function testAComposerPluginOfTheHeadCannotReplaceMainsHarness(): void
    {
        $this->repository->commitFile('broken', "the kernel does not boot\n");
        $head = $this->commitPlugin("file_put_contents('bin/instance', ".var_export(self::ALWAYS_READY, true).');');

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertSame('the kernel does not boot', $outcome->reason);
    }

    public function testAPackageBinaryOfTheHeadNamedInstanceDoesNotReplaceMainsHarness(): void
    {
        $this->repository->commitFile('broken', "the kernel does not boot\n");
        $this->repository->commitFile('package/instance', self::ALWAYS_READY);
        $this->repository->commitFile('package/composer.json', (string) json_encode(['name' => 'intakt-test/instance', 'version' => '1.0.0', 'bin' => ['instance']]));
        $head = $this->repository->commitFile('composer.json', (string) json_encode([
            'name' => 'intakt-test/boot',
            'repositories' => [['type' => 'path', 'url' => 'package', 'options' => ['symlink' => false]], ['packagist.org' => false]],
            'require' => ['intakt-test/instance' => '1.0.0'],
            'config' => ['bin-dir' => 'bin'],
        ]));

        $outcome = $this->bootWithMainsHarness($head);

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertSame('the kernel does not boot', $outcome->reason);
    }

    public function testASecretOfTheBootingInstallReachesNeitherTheInstallNorTheHarness(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\nfile_put_contents('php://fd/3', json_encode(['status' => 'failed', 'reason' => var_export(getenv('INTAKT_TEST_SECRET'), true)]));\n");
        $_ENV['INTAKT_TEST_SECRET'] = 'from-dotenv';
        putenv('INTAKT_TEST_SECRET=from-environment');

        try {
            $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));
        } finally {
            unset($_ENV['INTAKT_TEST_SECRET']);
            putenv('INTAKT_TEST_SECRET');
        }

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertSame('false', $outcome->reason);
    }

    public function testAHarnessThatReportsNoStatusIsACrashRatherThanAnException(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\necho \"log only\\n\";\n");

        $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertSame('bin/instance reported no status line.', $outcome->reason);
    }

    public function testAHarnessThatReportsAStatusItDoesNotDefineIsACrash(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\nfile_put_contents('php://fd/3', '{\"status\":\"maybe\"}');\n");

        $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertSame('bin/instance reported a status line it does not define: {"status":"maybe"}', $outcome->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusLinesOutsideTheContract(): iterable
    {
        yield 'ready with an undefined mode' => ['{"status":"ready","mode":"bogus"}'];
        yield 'ready with an extra key' => ['{"status":"ready","extra":1}'];
        yield 'failed with an empty reason' => ['{"status":"failed","reason":""}'];
        yield 'failed with an extra key' => ['{"status":"failed","reason":"broken","extra":1}'];
        yield 'failed with an undefined mode' => ['{"status":"failed","mode":"bogus","reason":"broken"}'];
        yield 'two status lines' => ["{\"status\":\"ready\"}\n{\"status\":\"ready\"}"];
    }

    #[DataProvider('statusLinesOutsideTheContract')]
    public function testAHarnessThatReportsAStatusLineOutsideTheContractIsACrash(string $line): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\nfile_put_contents('php://fd/3', ".var_export($line, true).");\n");

        $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));

        self::assertSame(BootState::Crashed, $outcome->state, $outcome->reason);
    }

    public function testAHarnessThatReportsReadyInWorkerModeIsBooted(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\nfile_put_contents('php://fd/3', '{\"status\":\"ready\",\"mode\":\"worker\"}'.\"\\n\");\n");

        $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));

        self::assertSame(BootState::Booted, $outcome->state, $outcome->reason);
    }

    public function testAHarnessThatReportsFailedInWorkerModeIsFailedWithItsReason(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\nfile_put_contents('php://fd/3', '{\"status\":\"failed\",\"mode\":\"worker\",\"reason\":\"no port\"}'.\"\\n\");\nexit(1);\n");

        $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));

        self::assertSame([BootState::Failed, 'no port'], [$outcome->state, $outcome->reason]);
    }

    public function testAHarnessThatReportsReadyButExitsWithAnErrorIsACrash(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\nfile_put_contents('php://fd/3', '{\"status\":\"ready\"}');\nexit(1);\n");

        $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));

        self::assertSame(BootState::Crashed, $outcome->state);
    }

    public function testAHeadThatIsNotInTheRepositoryIsACrashRatherThanAnException(): void
    {
        $outcome = $this->bootWithMainsHarness(str_repeat('a', 40));

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertStringContainsString('git worktree failed', $outcome->reason);
    }

    public function testAMainWithoutAHarnessIsACrashRatherThanAnException(): void
    {
        $head = $this->repository->removeFile('bin/instance');

        $outcome = new InstanceBoot()->of($this->at($head), new CommitId($head));

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertStringContainsString('There is no file "bin/instance"', $outcome->reason);
    }

    public function testAMainWhoseHarnessIsBinaryIsACrashRatherThanABootOfItsName(): void
    {
        $harness = $this->repository->commitFile('bin/instance', "<?php\0");

        $outcome = new InstanceBoot()->of($this->at($harness), new CommitId($harness));

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertSame('bin/instance is a binary file of 6 bytes, not a harness.', $outcome->reason);
    }

    public function testAMissingRepositoryIsACrashRatherThanAnException(): void
    {
        $target = new BootAt(new ChangeSource($this->repository->path.'-missing', 'main'), $this->main);

        $outcome = new InstanceBoot()->of($target, $this->main);

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertStringContainsString('is not a directory', $outcome->reason);
    }

    public function testTheSnapshotOfTheHeadIsRemovedAfterTheBoot(): void
    {
        $head = $this->repository->commitFile('src/Feature.php', "<?php\n");

        $this->bootWithMainsHarness($head);

        self::assertCount(1, $this->repository->worktrees());
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('composer.json', '{"name": "intakt-test/boot"}');
        $this->main = new CommitId($this->repository->commitFile('bin/instance', self::HARNESS));
        $this->repository->checkoutNewBranch('feature');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->repository->path.'-composer-home', $this->repository->path.'-home']);
        $this->repository->remove();
    }

    private function bootWithMainsHarness(string $head): BootOutcome
    {
        return new InstanceBoot()->of($this->at($head), $this->main);
    }

    // A PATH of every command of the real one except composer, plus the given stubs (name to file mode and script), so a test decides whether composer is missing, not executable or failing.
    /**
     * @param array<string, array{int, string}> $stubs
     */
    private function bootWithoutComposerAnd(string $head, array $stubs, ?InstanceBoot $boot = null): BootOutcome
    {
        $tools = $this->repository->path.'-tools';
        mkdir($tools);
        $path = getenv('PATH');
        $this->linkCommandsExceptComposer((string) $path, $tools);

        foreach ($stubs as $stub => [$mode, $body]) {
            file_put_contents($tools.'/'.$stub, $body);
            chmod($tools.'/'.$stub, $mode);
        }

        putenv('PATH='.$tools);

        try {
            return ($boot ?? new InstanceBoot())->of($this->at($head), $this->main);
        } finally {
            putenv(false === $path ? 'PATH' : 'PATH='.$path);
            new Filesystem()->remove($tools);
        }
    }

    // Every command rather than only git and sh, because git may be a wrapper that calls other commands; the first folder of the PATH wins, as in a shell.
    private function linkCommandsExceptComposer(string $path, string $tools): void
    {
        foreach (explode(':', $path) as $folder) {
            foreach ((array) glob($folder.'/*') as $command) {
                $link = $tools.'/'.basename((string) $command);

                if ('composer' !== basename($link) && !is_link($link)) {
                    symlink((string) $command, $link);
                }
            }
        }
    }

    private function writeComposerAuth(string $composerHome): void
    {
        mkdir($composerHome, 0o700, true);
        file_put_contents($composerHome.'/auth.json', json_encode(['github-oauth' => ['github.com' => self::PROBE_TOKEN]]));
    }

    // The plugin reads the token through Composer's config rather than the environment, because that is how Composer hands an auth.json of the booting install to the head's code; the variables are restored so the other tests keep the install's own home.
    /**
     * @param array<string, string|false> $variables
     */
    private function githubOauthAPluginSeesWith(array $variables): string
    {
        $seen = $this->repository->path.'-plugin-saw';
        $head = $this->commitPlugin("file_put_contents('".$seen."', json_encode(\$composer->getConfig()->get('github-oauth')));");
        $previous = [];

        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            putenv(false === $value ? $name : $name.'='.$value);
        }

        try {
            $outcome = $this->bootWithMainsHarness($head);
        } finally {
            foreach ($previous as $name => $value) {
                putenv(false === $value ? $name : $name.'='.$value);
            }
        }

        self::assertSame(BootState::Booted, $outcome->state, $outcome->reason);
        $saw = is_file($seen) ? (string) file_get_contents($seen) : 'the plugin did not run';
        @unlink($seen);

        return $saw;
    }

    // A local path package the head allows as a Composer plugin, so its activation runs the given PHP unless plugins are off.
    private function commitPlugin(string $onActivate): string
    {
        $this->repository->commitFile('plugin/composer.json', (string) json_encode([
            'name' => 'intakt-test/plugin', 'version' => '1.0.0', 'type' => 'composer-plugin',
            'require' => ['composer-plugin-api' => '^2.0'],
            'autoload' => ['classmap' => ['TestPlugin.php']],
            'extra' => ['class' => 'TestPlugin'],
        ]));
        $this->repository->commitFile('plugin/TestPlugin.php', "<?php\nuse Composer\\Composer;\nuse Composer\\IO\\IOInterface;\n"
            ."final class TestPlugin implements \\Composer\\Plugin\\PluginInterface {\n"
            .'public function activate(Composer $composer, IOInterface $io): void { '.$onActivate." }\n"
            ."public function deactivate(Composer \$composer, IOInterface \$io): void {}\n"
            ."public function uninstall(Composer \$composer, IOInterface \$io): void {}\n}\n");

        return $this->repository->commitFile('composer.json', (string) json_encode([
            'name' => 'intakt-test/boot',
            'repositories' => [['type' => 'path', 'url' => 'plugin', 'options' => ['symlink' => false]], ['packagist.org' => false]],
            'require' => ['intakt-test/plugin' => '1.0.0'],
            'config' => ['allow-plugins' => ['intakt-test/plugin' => true]],
        ]));
    }

    private function at(string $commit): BootAt
    {
        return new BootAt(new ChangeSource($this->repository->path, 'main'), new CommitId($commit));
    }
}
