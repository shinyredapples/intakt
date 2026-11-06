<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation;

use Closure;
use LogicException;
use PHPUnit\Runner\IssueTriggerResolver\Resolution;
use PHPUnit\Runner\IssueTriggerResolver\Resolver;
use ReflectionFunction;
use ReflectionMethod;

// Because an autoloader includes a class that deprecates itself on load, PHPUnit's default resolver would rate it as one vendor package causing it.
final class LoadTimeDeprecationResolver implements Resolver
{
    public function resolve(array $trace, string $message): Resolution
    {
        $callee = $this->fileOf($trace[0] ?? []);
        $frames = array_slice($trace, 1);
        $autoload = $this->innermostAutoloaderCall($frames);

        foreach (array_slice($frames, $autoload ?? 0) as $frame) {
            $file = $this->fileOf($frame);

            if (null !== $file) {
                return new Resolution($callee, $file);
            }
        }

        return new Resolution($callee, null);
    }

    // Because the frame of an autoloader call carries the file of the code that needed the class, or none when an internal function such as class_exists() needed it; any registered callable shape counts, so a closure loader cannot hide the caller.
    /**
     * @param list<array<string, mixed>> $frames
     */
    private function innermostAutoloaderCall(array $frames): ?int
    {
        $autoloaders = array_map($this->identityOf(...), spl_autoload_functions());

        foreach ($frames as $index => $frame) {
            foreach ($autoloaders as [$class, $function]) {
                if (($frame['function'] ?? null) === $function && (null === $class || ($frame['class'] ?? null) === $class)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @return array{?string, string}
     */
    private function identityOf(callable $autoloader): array
    {
        $reflection = match (true) {
            is_array($autoloader) => new ReflectionMethod($autoloader[0], $autoloader[1]),
            is_string($autoloader) && str_contains($autoloader, '::') => ReflectionMethod::createFromMethodName($autoloader),
            $autoloader instanceof Closure, is_string($autoloader) => new ReflectionFunction($autoloader),
            is_object($autoloader) => new ReflectionMethod($autoloader, '__invoke'),
            default => throw new LogicException('An autoloader of an unknown callable shape is registered.'),
        };

        return $reflection instanceof ReflectionMethod
            ? [$reflection->getDeclaringClass()->getName(), $reflection->getName()]
            : [null, $reflection->getName()];
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
}
