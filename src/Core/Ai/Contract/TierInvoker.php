<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use NoDiscard;
use Symfony\AI\Platform\Result\DeferredResult;

interface TierInvoker
{
    // The tier's own model while its provider is enabled and has a free account, else its first substitute that does; no review asks it yet, because the vendor still judges the tier's own provider.
    /**
     * @throws TierResolutionFailure
     * @throws EveryKeyRejected
     * @throws EveryAccountCoolingDown
     * @throws EveryProviderOut
     */
    public function answering(string $project, Tier $tier): ResolvedModel;

    #[NoDiscard('the answer is only in the result')]
    public function invoke(ResolvedModel $model, TierRequest $request): DeferredResult;
}
