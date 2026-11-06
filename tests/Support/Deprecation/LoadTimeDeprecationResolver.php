<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation;

use Composer\Autoload\ClassLoader;
use PHPUnit\Runner\IssueTriggerResolver\Resolution;
use PHPUnit\Runner\IssueTriggerResolver\Resolver;

// Because Composer's autoloader includes a class that deprecates itself on load, PHPUnit's default resolver would rate it as one vendor package causing it.
final class LoadTimeDeprecationResolver implements Resolver
{
    public function resolve(array $trace, string $message): Resolution
    {
        $callee = $this->fileOf($trace[0] ?? []);

        foreach (array_slice($trace, 1) as $frame) {
            $file = $this->fileOf($frame);

            if (null !== $file && !$this->isAutoloader($file)) {
                return new Resolution($callee, $file);
            }
        }

        return new Resolution($callee, null);
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @return non-empty-string|null
     */
    private function fileOf(array $frame): ?string
    {
        $file = $frame['file'] ?? null;

        return \is_string($file) && '' !== $file ? $file : null;
    }

    private function isAutoloader(string $file): bool
    {
        return new \ReflectionClass(ClassLoader::class)->getFileName() === $file;
    }
}
