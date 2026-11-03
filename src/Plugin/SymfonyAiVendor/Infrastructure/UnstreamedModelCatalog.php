<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Override;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;

// Because a caller asks the catalog whether to stream, and a provider that answers in one piece must say it cannot.
final class UnstreamedModelCatalog extends FallbackModelCatalog
{
    #[Override]
    public function getModel(string $modelName): Model
    {
        $model = parent::getModel($modelName);

        return new Model($model->getName(), array_values(array_filter($model->getCapabilities(), static fn (Capability $capability): bool => Capability::OUTPUT_STREAMING !== $capability)), $model->getOptions());
    }
}
