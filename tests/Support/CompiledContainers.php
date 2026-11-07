<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

// One cache folder per set of build inputs, so tests that build from the same inputs reuse one container while a test with other inputs never sees a container built from the old ones.
final readonly class CompiledContainers
{
    private function __construct(private string $directory, private mixed $sharedCache)
    {
    }

    // Beside the shared test cache rather than inside it, so the kernel tests that run after the class keep their compiled container.
    public static function beside(string $sharedCacheDirectory, string $name): self
    {
        return new self($sharedCacheDirectory.'-'.$name, $_SERVER['APP_CACHE_DIR'] ?? null);
    }

    /**
     * @param array<mixed> $inputs
     */
    public function useFor(array $inputs): void
    {
        $_SERVER['APP_CACHE_DIR'] = $this->directory.'/'.hash('xxh128', serialize($inputs));
    }

    public function restoreTheSharedCache(): void
    {
        unset($_SERVER['APP_CACHE_DIR']);

        if (null !== $this->sharedCache) {
            $_SERVER['APP_CACHE_DIR'] = $this->sharedCache;
        }
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->directory);
    }
}
