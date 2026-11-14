<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use NoDiscard;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\PlatformInterface;

interface Reviewer
{
    // The model name is the platform's to resolve, so a caller can route it (the vendor passes a tier).
    /**
     * @param non-empty-string $model
     *
     * @throws ConversationAlreadyStarted
     * @throws IncompleteStreamException
     * @throws VerdictUnreadable
     */
    #[NoDiscard('a verdict that is not read approves nothing')]
    public function review(ReviewRequest $request, PlatformInterface $platform, string $model): Verdict;
}
