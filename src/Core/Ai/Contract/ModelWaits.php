<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use Symfony\AI\Platform\Model;

// Asked by tier selection of a provider's own platform, because only the platform that sends the call knows a model's pinned router provider, so selection and the call read the same wait.
interface ModelWaits
{
    // Zero while an account can take the model, else the shortest wait of the accounts, at least a second.
    public function secondsUntilModelIsFree(string|Model $model): int;
}
