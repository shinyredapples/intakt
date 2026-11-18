<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Symfony\Component\Messenger\MessageBusInterface;

// Dispatches on the command bus from inside a handler, as the dashboard's start reaches RunReviewRound through StartReview.
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
