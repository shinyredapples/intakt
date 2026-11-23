<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Mcp\Contract\OwnerToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Two processes, because docker compose exec starts a command that never ran the entrypoint, so it gets only what compose.yaml sets and not the token the entrypoint exported.
#[CoversClass(OwnerToken::class)]
final class ComposeExecOwnerTokenTest extends TestCase
{
    private string $directory;

    public function testACommandStartedApartFromTheEntrypointPrintsTheTokenTheEntrypointMade(): void
    {
        $made = $this->tokenTheEntrypointMade();

        $show = new Process(['php', 'bin/console', 'intakt:sample:show', $this->directory.'/sample-repository'], dirname(__DIR__, 4), [
            'APP_ENV' => 'test',
            'MCP_OWNER_TOKEN' => '',
            'MCP_OWNER_KEPT_FILE' => $this->keptFile(),
        ]);
        $show->mustRun();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $made);
        self::assertStringContainsString('--header "Authorization: Bearer '.$made.'"', $show->getOutput());
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-compose-exec-token-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    // The entrypoint runs with a stand-in php whose seed step fails, so the start ends right after it has made the token, without a server.
    private function tokenTheEntrypointMade(): string
    {
        $programs = $this->directory.'/stubs';
        new Filesystem()->dumpFile($programs.'/php', "#!/bin/bash\ncase \"\$*\" in *intakt:sample:seed*) exit 1;; esac\nexit 0\n");
        new Filesystem()->chmod($programs.'/php', 0o755);

        new Process(['docker/entrypoint.sh'], dirname(__DIR__, 4), [
            'PATH' => $programs.':'.getenv('PATH'),
            'APP_SECRET' => 'compose-exec-owner-token-test-secret',
            'MERCURE_JWT_SECRET' => 'compose-exec-owner-token-test-secret',
            'MERCURE_JWT_ISSUER' => 'http://localhost',
            'MCP_OWNER_TOKEN' => false,
            'MCP_OWNER_ACCESS' => false,
            // Cleared, because the start removes the folders they name, which a caller's setting would point outside this test.
            'APP_CACHE_DIR' => false,
            'APP_BUILD_DIR' => false,
            'INTAKT_SAMPLE_REPOSITORY' => $this->directory.'/sample-repository',
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'MCP_OWNER_KEPT_FILE' => $this->keptFile(),
        ])->run();

        return trim(new Filesystem()->readFile($this->keptFile()));
    }

    private function keptFile(): string
    {
        return $this->directory.'/mcp-owner-token';
    }
}
