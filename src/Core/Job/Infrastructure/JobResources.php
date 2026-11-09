<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// What one run created, recorded at the moment of creation so a failure halfway still removes it.
final class JobResources
{
    /** @var list<string> */
    private array $containers = [];
    private ?string $network = null;

    public function addContainer(string $id): void
    {
        $this->containers[] = $id;
    }

    public function setNetwork(string $id): void
    {
        $this->network = $id;
    }

    /**
     * @return list<string>
     */
    public function containers(): array
    {
        return $this->containers;
    }

    public function network(): ?string
    {
        return $this->network;
    }
}
