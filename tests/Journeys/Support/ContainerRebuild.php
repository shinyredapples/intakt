<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Symfony\Component\Filesystem\Filesystem;

// Removed by hand and warmed up in a new process, as docker/entrypoint.sh does, because the framework's cache:clear keeps a container compiled in the second it starts, so one the instance or an earlier command built without the journey's settings would survive.
final readonly class ContainerRebuild
{
    /**
     * @param list<string>                                           $directories
     * @param Closure(list<string>, array<string,string>, int): string $console
     */
    public function __construct(
        private array $directories,
        private Closure $console,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param array<string,string> $environment
     */
    public function rebuild(array $environment, int $seconds): void
    {
        $this->filesystem->remove($this->directories);
        ($this->console)(['cache:warmup'], $environment, $seconds);
    }
}
