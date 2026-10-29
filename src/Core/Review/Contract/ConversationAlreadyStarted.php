<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use DomainException;
use Symfony\Component\Uid\AbstractUid;
use Throwable;

final class ConversationAlreadyStarted extends DomainException
{
    public static function for(AbstractUid $conversation, Throwable $previous): self
    {
        return new self(sprintf('The reviewer conversation "%s" was already started; each reviewer of a round needs its own.', $conversation->toRfc4122()), previous: $previous);
    }
}
