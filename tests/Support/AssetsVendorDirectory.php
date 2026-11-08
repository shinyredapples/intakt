<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Override;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

// Because the test environment maps its vendor folder inside the cache directory, and the asset mapper refuses a mapped folder that does not exist in debug, on every page.
final readonly class AssetsVendorDirectory implements CacheWarmerInterface
{
    public function __construct(private RemotePackageStorage $storage)
    {
    }

    #[Override]
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        new Filesystem()->mkdir($this->storage->getStorageDir());

        return [];
    }
}
