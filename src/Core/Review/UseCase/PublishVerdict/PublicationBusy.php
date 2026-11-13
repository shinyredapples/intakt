<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable, because a publication that ran out of retries while another one held its change would leave a withdrawal unpublished for good; the delay is the retry strategy's.
final class PublicationBusy extends RecoverableMessageHandlingException
{
    public static function of(string $change): self
    {
        return new self(sprintf('Another verdict of %s is being published.', $change));
    }
}
