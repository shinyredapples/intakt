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

    // How long a call of the model must wait, zero when it can go out at once; read with the router provider the model pins, so a 429 that cooled only the pin is waited for like a cooled account.
    public function secondsUntilFree(ResolvedModel $model): int;

    #[NoDiscard('the answer is only in the result')]
    public function invoke(ResolvedModel $model, TierRequest $request): DeferredResult;
}
