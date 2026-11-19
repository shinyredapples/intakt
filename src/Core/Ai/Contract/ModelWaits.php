<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use Symfony\AI\Platform\Model;

// Asked by tier selection of a provider's own platform, because only the platform that sends the call knows a model's pinned router provider, so selection and the call read the same wait.
interface ModelWaits
{
    // At least a second while no account can take the model, so a cooling model is never reported free and chosen again at once.
    public function secondsUntilModelIsFree(string|Model $model): int;
}
