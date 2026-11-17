<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class VaultTestsLeaveNoVaultFolderBehindTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/vault-order-'.bin2hex(random_bytes(4));
        mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    // The order the tests gate's worker produced when it ordered by recorded durations.
    public function testTheKeptKeyWiringTestPassesRightAfterTheAiProvidersTestInOneProcess(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(
            [PHP_BINARY, $root.'/vendor/bin/phpunit', $root.'/tests/Application/AiProvidersTest.php', $root.'/tests/Core/Ai/Infrastructure/KeptProviderKeyVariablesWiringTest.php'],
            $root,
            ['APP_CACHE_DIR' => $this->scratch.'/cache', 'TMPDIR' => $this->scratch],
            null,
            600,
        );

        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
