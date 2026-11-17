<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

interface ProviderCatalog
{
    /**
     * @return non-empty-list<ProviderDescriptor>
     */
    public function descriptors(): array;

    public function descriptor(string $id): ?ProviderDescriptor;
}
