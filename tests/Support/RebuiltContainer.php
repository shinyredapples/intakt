<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Symfony\Component\DependencyInjection\ContainerInterface;

// Static analysis checks container ids against the dumped test container; a kernel built with other variables or another configuration defines ids that dump cannot know, so a test reads them through this instead.
final readonly class RebuiltContainer
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function get(string $id): object
    {
        return $this->container->get($id);
    }

    /**
     * @return array<mixed>|bool|float|int|string|null
     */
    public function getParameter(string $name): array|bool|float|int|string|null
    {
        return $this->container->getParameter($name);
    }
}
