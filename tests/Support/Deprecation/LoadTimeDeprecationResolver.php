<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation;

use PHPUnit\Runner\IssueTriggerResolver\Resolution;
use PHPUnit\Runner\IssueTriggerResolver\Resolver;

// Because an autoloader includes a class that deprecates itself on load, PHPUnit's default resolver would rate it as one vendor package causing it.
final readonly class LoadTimeDeprecationResolver implements Resolver
{
    public function resolve(array $trace, string $message): Resolution
    {
        $callee = $this->fileOf($trace[0] ?? []);
        $frames = array_slice($trace, 1);
        $autoload = $this->autoloaderCallThatRaisedIt($frames);

        foreach (array_slice($frames, $autoload ?? 0) as $frame) {
            $file = $this->fileOf($frame);

            if (null !== $file) {
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

    // Because the frame of an autoloader call carries the file of the code that needed the class, or none when an internal function such as class_exists() needed it; any registered callable shape counts, so a closure loader cannot hide the caller. Only the loading itself may be skipped: a frame outside the autoloader before its call is code that called the deprecated symbol while the file loaded, and it stays the caller.
    /**
     * @param list<array<string, mixed>> $frames
     */
    private function autoloaderCallThatRaisedIt(array $frames): ?int
    {
        $autoloaders = array_map(RegisteredAutoloader::of(...), spl_autoload_functions());

        foreach ($frames as $index => $frame) {
            if (array_any($autoloaders, static fn (RegisteredAutoloader $autoloader): bool => $autoloader->isCalledIn($frame))) {
                return $index;
            }

            if (!array_any($autoloaders, static fn (RegisteredAutoloader $autoloader): bool => $autoloader->runs($frame))) {
                return null;
            }
        }

        return null;
    }
}
