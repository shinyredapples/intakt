<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GatesCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GatesCache::class)]
final class GatesCacheTest extends TestCase
{
    private string|false $inherited;

    public function testTheFolderTheVariableNamesIsTheGatesCache(): void
    {
        putenv(GatesCache::VARIABLE.'=/work/cache');

        self::assertSame('/work/cache', GatesCache::directory());
    }

    public function testWithoutTheVariableTheGatesCacheIsInTheSystemTempFolder(): void
    {
        putenv(GatesCache::VARIABLE);

        self::assertSame(sys_get_temp_dir().'/intakt-gates', GatesCache::directory());
    }

    public function testAnEmptyVariableCountsAsNotSet(): void
    {
        putenv(GatesCache::VARIABLE.'=');

        self::assertSame(sys_get_temp_dir().'/intakt-gates', GatesCache::directory());
    }

    protected function setUp(): void
    {
        $this->inherited = getenv(GatesCache::VARIABLE);
    }

    protected function tearDown(): void
    {
        putenv(false === $this->inherited ? GatesCache::VARIABLE : GatesCache::VARIABLE.'='.$this->inherited);
    }
}
