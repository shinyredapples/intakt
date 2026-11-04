<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Kernel;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\AI\McpBundle\McpBundle;

#[CoversClass(Kernel::class)]
final class KernelTest extends KernelTestCase
{
    public function testTheKernelBootsWithTheAiAndMcpBundles(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('AiBundle', $kernel->getBundles());
        self::assertInstanceOf(AiBundle::class, $kernel->getBundle('AiBundle'));
        self::assertInstanceOf(McpBundle::class, $kernel->getBundle('McpBundle'));
    }

    public function testTheDefaultDatabaseIsSqlite(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? null;

        self::assertIsString($url);
        self::assertStringStartsWith('sqlite:', $url);
    }
}
