<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

// Stands in for the embedding platform when no OpenAI account is enabled, so the container still builds and the worker that needs embeddings fails with the reason, which sends the message to the failure transport instead of losing it.
final readonly class EmbeddingsNotConfigured implements PlatformInterface
{
    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        throw new ProjectMemoryNeedsEmbeddingModel();
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        throw new ProjectMemoryNeedsEmbeddingModel();
    }
}
