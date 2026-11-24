<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ExpireConversations;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\Review\Infrastructure\StoredConversations;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommandHandler(handles: ExpireConversations::class)]
final readonly class ExpireConversationsHandler
{
    public function __construct(
        private StoredConversations $conversations,
        private ClockInterface $clock,
        // Null keeps conversations forever.
        #[Autowire(param: ReviewServices::CONVERSATION_DAYS)]
        private ?int $days,
    ) {
    }

    public function __invoke(): void
    {
        if (null === $this->days) {
            return;
        }

        $this->conversations->deleteLastChangedBefore($this->clock->now()->modify(sprintf('-%d days', $this->days)));
    }
}
