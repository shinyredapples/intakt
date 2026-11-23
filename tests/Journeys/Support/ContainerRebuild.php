<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Intakt\Kernel;
use LogicException;
use ReflectionMethod;
use Symfony\Component\Filesystem\Filesystem;

// Not cache:clear, because it keeps a container compiled in the second it starts (so one built before the journey's settings survives) and marks the service files the instance's running worker still loads as old, so the next compile removes them.
final readonly class ContainerRebuild
{
    /**
     * @param Closure(list<string>, array<string,string>, int): string $console
     */
    public function __construct(
        private string $loader,
        private Closure $console,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    // Named by the kernel itself, because only it knows the class it compiles; protected there, so read through reflection.
    public static function loaderOf(Kernel $kernel, string $buildDirectory): string
    {
        $class = new ReflectionMethod($kernel, 'getContainerClass')->invoke($kernel);

        return is_string($class) ? $buildDirectory.'/'.$class.'.php' : throw new LogicException('The kernel names no container class.');
    }

    // Only the loader, because without it the next process's kernel compiles again from the settings it is given, into a new directory beside the one the worker uses; no other process compiles one in between, because the instance boots a kernel only for a command or request the journey sends, and the journey waits for this warmup before it sends one.
    /**
     * @param array<string,string> $environment
     */
    public function rebuild(array $environment, int $seconds): void
    {
        $this->filesystem->remove($this->loader);
        ($this->console)(['cache:warmup'], $environment, $seconds);
    }
}
