<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use LogicException;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

// The platform of the configured reviewer agent, which is there only for its MCP toolbox: ReviewerAgent builds the Agent of each review with the platform of the tier that answers it, so nothing may call this one.
final readonly class PlatformChosenPerReview implements PlatformInterface
{
    private const string REASON = 'The reviewer agent is configured only for its MCP tools; ReviewerAgent calls the platform of the tier that answers each review.';

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        throw new LogicException(self::REASON);
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        throw new LogicException(self::REASON);
    }
}
