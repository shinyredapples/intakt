<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Symfony\Component\Messenger\MessageBusInterface;

// Nested, because the dashboard's start reaches RunReviewRound through StartReview, so a refusal passes two command-bus dispatches.
#[AsCommandHandler]
final readonly class RelayRefusalHandler
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function __invoke(RelayRefusal $command): void
    {
        $this->commandBus->dispatch(new RefuseThing($command->refusal));
    }
}
