<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use NoDiscard;
use Symfony\AI\Platform\PlatformInterface;

interface Sizer
{
    // Null for an answer that is no valid verdict, because a call that failed to answer must neither park nor split a task.
    /**
     * @param non-empty-string $model
     */
    #[NoDiscard('a verdict that is not read sizes nothing')]
    public function size(SizingRequest $request, PlatformInterface $platform, string $model): ?SizingVerdict;
}
