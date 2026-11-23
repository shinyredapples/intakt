<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Serial because the AI providers test it runs creates config/secrets/, which the workers share.
#[CoversNothing]
#[Group('serial')]
final class VaultTestsLeaveNoVaultFolderBehindTest extends TestCase
{
    private string $scratch;

    private bool $vaultsBefore;

    // Forces the order a check that sorts by recorded durations put in one worker, because that order failed on main (#2434).
    public function testTheKeptKeyWiringTestPassesRightAfterTheAiProvidersTestInOneProcess(): void
    {
        $process = $this->runInOneProcess('tests/Application/AiProvidersTest.php', 'tests/Core/Ai/Infrastructure/KeptProviderKeyVariablesWiringTest.php');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function testTheAiProvidersTestLeavesNoVaultsFolderBehind(): void
    {
        $this->runInOneProcess('tests/Application/AiProvidersTest.php');

        self::assertSame($this->vaultsBefore, is_dir($this->vaults()));
    }

    public function testTheKeptKeyBuildWarningTestLeavesNoVaultsFolderBehind(): void
    {
        $process = $this->runInOneProcess('tests/Application/KeptKeyBuildWarningTest.php');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame($this->vaultsBefore, is_dir($this->vaults()));
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/vault-order-'.bin2hex(random_bytes(4));
        mkdir($this->scratch);
        $this->vaultsBefore = is_dir($this->vaults());
    }

    // Removes a folder the run left behind, so one test that finds the leak does not hide it from the next.
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
        if (!$this->vaultsBefore && is_dir($this->vaults()) && [] === array_diff((array) scandir($this->vaults()), ['.', '..'])) {
            rmdir($this->vaults());
        }
    }

    private function vaults(): string
    {
        return dirname(__DIR__, 2).'/config/secrets';
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
