<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(AssetsVendorDirectory::class)]
final class AssetsVendorDirectoryTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir().'/assets-vendor-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->cache);
    }

    public function testWarmingUpCreatesTheVendorFolderOfAFreshCache(): void
    {
        $this->warmUp();

        self::assertDirectoryExists($this->cache.'/assets/vendor');
    }

    public function testWarmingUpKeepsTheScriptsOfAnExistingVendorFolder(): void
    {
        new Filesystem()->dumpFile($this->cache.'/assets/vendor/stand-in.js', '');

        $this->warmUp();

        self::assertFileExists($this->cache.'/assets/vendor/stand-in.js');
    }

    public function testTheTestEnvironmentCannotDoWithoutIt(): void
    {
        self::assertFalse(new AssetsVendorDirectory(new RemotePackageStorage($this->cache.'/assets/vendor'))->isOptional());
    }

    private function warmUp(): void
    {
        new AssetsVendorDirectory(new RemotePackageStorage($this->cache.'/assets/vendor'))->warmUp($this->cache);
    }
}
