<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use LogicException;
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
        throw new LogicException('Project memory needs an embedding model: enable an OpenAI account (set OPENAI_API_KEY) to index it.');
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        throw new LogicException('Project memory needs an embedding model: enable an OpenAI account (set OPENAI_API_KEY) to index it.');
    }
}
