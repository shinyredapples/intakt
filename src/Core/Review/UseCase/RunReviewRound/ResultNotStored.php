<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

// Unrecoverable, because a retry would call a reviewer that already answered, and pay for it, again; the failed message's listener blocks the reviewer instead.
final class ResultNotStored extends UnrecoverableMessageHandlingException
{
    public static function after(int $attempts, ReviewEventNotRecorded $refused): self
    {
        return new self(sprintf('The reviewer\'s result was not stored in %d attempts: %s', $attempts, $refused->getMessage()), previous: $refused);
    }
}
