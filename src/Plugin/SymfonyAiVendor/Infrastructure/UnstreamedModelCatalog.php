<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Override;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;

// Tells a caller that asks the catalog whether to stream that this provider answers in one piece.
final class UnstreamedModelCatalog extends FallbackModelCatalog
{
    #[Override]
    public function getModel(string $modelName): Model
    {
        $model = parent::getModel($modelName);

        return new Model($model->getName(), array_values(array_filter($model->getCapabilities(), static fn (Capability $capability): bool => Capability::OUTPUT_STREAMING !== $capability)), $model->getOptions());
    }
}
