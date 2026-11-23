<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledContainers::class)]
final class CompiledContainersTest extends TestCase
{
    private mixed $cacheBefore = null;

    private bool $cacheWasSet = false;

    public function testTheSharedCacheSetBeforeComesBack(): void
    {
        $_SERVER['APP_CACHE_DIR'] = 'var/cache/shared';
        $containers = CompiledContainers::beside('var/cache/shared', 'example');
        $containers->useFor(['config' => 'one']);

        $containers->restoreTheSharedCache();

        self::assertSame('var/cache/shared', $_SERVER['APP_CACHE_DIR']);
    }

    public function testWithoutASharedCacheSetBeforeNoneIsLeftBehind(): void
    {
        unset($_SERVER['APP_CACHE_DIR']);
        $containers = CompiledContainers::beside('var/cache/shared', 'example');
        $containers->useFor(['config' => 'one']);

        $containers->restoreTheSharedCache();

        self::assertArrayNotHasKey('APP_CACHE_DIR', $_SERVER);
    }

    protected function setUp(): void
    {
        $this->cacheWasSet = array_key_exists('APP_CACHE_DIR', $_SERVER);
        $this->cacheBefore = $_SERVER['APP_CACHE_DIR'] ?? null;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_CACHE_DIR']);

        if ($this->cacheWasSet) {
            $_SERVER['APP_CACHE_DIR'] = $this->cacheBefore;
        }
    }
}
