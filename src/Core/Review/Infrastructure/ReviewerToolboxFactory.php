<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\EventDispatcher\EventDispatcher;

final readonly class ReviewerToolboxFactory
{
    // Built per checkout because the tools and the listener hold the head under review; a failing tool answers the model with an error.
    public function create(Checkout $checkout): FaultTolerantToolbox
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, new DenyPathsOutsideCheckout($checkout));

        return new FaultTolerantToolbox(new Toolbox(
            [new ReadFile($checkout), new ListDirectory($checkout), new SearchFiles($checkout)],
            eventDispatcher: $dispatcher,
        ));
    }
}
