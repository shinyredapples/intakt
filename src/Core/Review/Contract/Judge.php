<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use NoDiscard;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\PlatformInterface;

interface Judge
{
    // The model name is the platform's to resolve, so a caller can route it (the vendor passes a tier).
    /**
     * @param non-empty-string $model
     *
     * @throws IncompleteStreamException
     */
    #[NoDiscard('a judgement that is not read decides nothing')]
    public function judge(JudgeRequest $request, PlatformInterface $platform, string $model): Judgement;
}
