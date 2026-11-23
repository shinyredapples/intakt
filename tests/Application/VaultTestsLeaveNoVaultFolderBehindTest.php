<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Serial because it compares the times of config/, which a test another worker runs could change meanwhile.
#[CoversNothing]
#[Group('serial')]
final class VaultTestsLeaveNoVaultFolderBehindTest extends TestCase
{
    private string $scratch;

    /** @var array{exists: bool, modified: int|false, changed: int|false} */
    private array $before;

    // Forces the order a check that sorts by recorded durations put in one worker, because that order failed on main (#2434).
    public function testTheKeptKeyWiringTestPassesRightAfterTheAiProvidersTestInOneProcess(): void
    {
        $process = $this->runInOneProcess('tests/Application/AiProvidersTest.php', 'tests/Core/Ai/Infrastructure/KeptProviderKeyVariablesWiringTest.php');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    // The quality check records a pass only for a tree nothing changed in while it ran, so a vault made and removed in config/ would keep it from ever recording one.
    public function testTheAiProvidersTestLeavesTheConfigFolderAsItFoundIt(): void
    {
        $process = $this->runInOneProcess('tests/Application/AiProvidersTest.php');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame($this->before, $this->configFolder());
    }

    public function testTheKeptKeyBuildWarningTestLeavesTheConfigFolderAsItFoundIt(): void
    {
        $process = $this->runInOneProcess('tests/Application/KeptKeyBuildWarningTest.php');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame($this->before, $this->configFolder());
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/vault-order-'.bin2hex(random_bytes(4));
        mkdir($this->scratch);
        $this->before = $this->configFolder();
    }

    // Removes a folder the run left behind, so one test that finds the leak does not hide it from the next.
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
        $vaults = dirname(__DIR__, 2).'/config/secrets';
        if (!$this->before['exists'] && is_dir($vaults) && [] === array_diff((array) scandir($vaults), ['.', '..'])) {
            rmdir($vaults);
        }
    }

    // Whole seconds, but every run here lasts longer than one, so a folder made and removed during it moves the times.
    /** @return array{exists: bool, modified: int|false, changed: int|false} */
    private function configFolder(): array
    {
        $config = dirname(__DIR__, 2).'/config';
        clearstatcache();

        return ['exists' => is_dir($config.'/secrets'), 'modified' => filemtime($config), 'changed' => filectime($config)];
    }

    private function runInOneProcess(string ...$files): Process
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(
            [PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-progress', ...array_map(static fn (string $file): string => $root.'/'.$file, $files)],
            $root,
            ['APP_CACHE_DIR' => $this->scratch.'/cache', 'TMPDIR' => $this->scratch],
            null,
            600,
        );
        $process->run();

        return $process;
    }
}
