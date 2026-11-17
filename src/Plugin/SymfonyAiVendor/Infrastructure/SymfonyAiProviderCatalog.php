<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Plugin\Vendor\Contract\ProviderCatalog;

// One list, so adding a provider a key can belong to is a descriptor class and an entry here, with no edit in Core.
final readonly class SymfonyAiProviderCatalog implements ProviderCatalog
{
    /**
     * @param non-empty-list<SymfonyAiProvider> $descriptors
     */
    public function __construct(
        private array $descriptors = [
            new AnthropicDescriptor(),
            new OpenAiDescriptor(),
            new HuggingFaceDescriptor(),
        ],
    ) {
    }

    public function descriptors(): array
    {
        return $this->descriptors;
    }

    public function descriptor(string $id): ?SymfonyAiProvider
    {
        return array_find($this->descriptors, static fn (SymfonyAiProvider $descriptor): bool => $descriptor->id() === $id);
    }
}
