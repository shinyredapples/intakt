<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation;

use PHPUnit\Runner\IssueTriggerResolver\Resolution;
use PHPUnit\Runner\IssueTriggerResolver\Resolver;
use ReflectionClass;

// Because an autoloader includes a class that deprecates itself on load, PHPUnit's default resolver would rate it as one vendor package causing it.
final class LoadTimeDeprecationResolver implements Resolver
{
    public function resolve(array $trace, string $message): Resolution
    {
        $callee = $this->fileOf($trace[0] ?? []);
        $autoloaders = $this->autoloaderFiles();

        foreach (array_slice($trace, 1) as $frame) {
            $file = $this->fileOf($frame);

            if (null !== $file && !in_array($file, $autoloaders, true)) {
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

        return is_string($file) && '' !== $file ? $file : null;
    }

    // Because a debug class loader (Symfony's DebugClassLoader) replaces Composer's autoloader and includes the class file itself.
    /**
     * @return list<string>
     */
    private function autoloaderFiles(): array
    {
        $files = [];

        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader)) {
                $files[] = new ReflectionClass($autoloader[0])->getFileName();
            }
        }

        return array_values(array_filter($files, is_string(...)));
    }
}
