<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable, because a judging that ran out of retries would leave its round without a judge_done and no door would ever judge it again; the delay is the provider's reset, or null for the retry strategy to decide.
final class JudgeLimited extends RecoverableMessageHandlingException
{
    public static function after(?int $resetInSeconds): self
    {
        return new self('The provider limited the judge\'s call.', retryDelay: null === $resetInSeconds ? null : $resetInSeconds * 1000);
    }
}
