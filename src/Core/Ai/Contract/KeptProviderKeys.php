<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface KeptProviderKeys
{
    public function keep(ProviderKey $key): void;

    /**
     * @throws KeptProviderKeyUnreadable
     */
    public function read(): ?KeptProviderKey;

    /**
     * @throws KeptProviderKeyUnreadable
     */
    public function configured(): ?ConfiguredKey;
}
