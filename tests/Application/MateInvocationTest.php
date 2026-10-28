<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class MateInvocationTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testTheToolListOffersTheServerAndContainerTools(): void
    {
        $tools = $this->mate('tools:list');

        self::assertIsArray($tools['tools']);
        self::assertArrayHasKey('server-info', $tools['tools']);
        self::assertArrayHasKey('symfony-services', $tools['tools']);
        self::assertArrayHasKey('symfony-dotenv-check', $tools['tools']);
    }

    public function testTheServerInfoToolReportsTheRuntimeOfThisInstall(): void
    {
        $info = $this->mate('tools:call', 'server-info');

        self::assertSame(PHP_VERSION, $info['php_version']);
    }

    /**
     * @return array<mixed>
     */
    private function mate(string ...$arguments): array
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/mate', ...$arguments, '--format=json'], self::ROOT);
        $process->mustRun();
        $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($output);

        return $output;
    }
}
