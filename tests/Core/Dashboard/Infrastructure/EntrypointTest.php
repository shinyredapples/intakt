<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

// The container must name a missing value before it starts anything, because FrankenPHP exits without a reason when the Caddyfile cannot read one.
#[CoversNothing]
final class EntrypointTest extends TestCase
{
    public function testAMissingRequiredValueIsNamedOnStderrBeforeAnythingStarts(): void
    {
        $process = $this->entrypointWith(['MERCURE_JWT_ISSUER' => false]);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('MERCURE_JWT_ISSUER', $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }

    public function testEveryValueTheCaddyfileReadsWithoutADefaultIsCheckedByTheEntrypoint(): void
    {
        preg_match_all('/\{\$([A-Z_]+)\}/', $this->read('Caddyfile'), $matches);
        $entrypoint = $this->read('docker/entrypoint.sh');

        self::assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $name) {
            self::assertStringContainsString(sprintf('${%s:?', $name), $entrypoint, sprintf('The entrypoint does not check %s.', $name));
        }
    }

    /**
     * @param array<string, false|string> $overrides
     */
    private function entrypointWith(array $overrides): Process
    {
        $env = ['APP_SECRET' => 'x', 'MERCURE_JWT_SECRET' => 'y', 'MERCURE_JWT_ISSUER' => 'http://localhost:7781'];
        $process = new Process(['bash', 'docker/entrypoint.sh'], dirname(__DIR__, 4), [...$env, ...$overrides]);
        $process->run();

        return $process;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }
}
