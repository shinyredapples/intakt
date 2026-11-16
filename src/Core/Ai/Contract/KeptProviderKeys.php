<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface KeptProviderKeys
{
    /**
     * @throws KeptProviderKeyNotWritten
     */
    public function keep(ProviderKey $key): void;

    /**
     * @throws KeptProviderKeyUnreadable
     */
    public function read(): ?ProviderKey;

    /**
     * @throws KeptProviderKeyUnreadable
     */
    public function configured(): ?ConfiguredKey;
}
