<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

// Its own class so the bridge's ModelCatalog imports plainly; Anthropic has one with the same name.
final readonly class OpenAiBridgeCatalog
{
    public function catalog(): ModelCatalogInterface
    {
        return new ModelCatalog();
    }
}
