<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use NoDiscard;
use Symfony\AI\Platform\Result\DeferredResult;

interface TierInvoker
{
    // Chosen once before a review starts and kept for every call of it, because a review is never continued by another vendor: the tier's own model while its provider has a free account, else its first substitute with one.
    /**
     * @throws TierResolutionFailure
     * @throws EveryAccountCoolingDown
     * @throws EveryProviderOut
     */
    public function answering(string $project, Tier $tier): ResolvedModel;

    #[NoDiscard('the answer is only in the result')]
    public function invoke(ResolvedModel $model, TierRequest $request): DeferredResult;
}
