<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use NoDiscard;
use Symfony\AI\Platform\Result\DeferredResult;

interface TierInvoker
{
    /**
     * @throws TierResolutionFailure
     */
    #[NoDiscard('the answer is only in the result')]
    public function invoke(string $project, Tier $tier, TierRequest $request): DeferredResult;
}
