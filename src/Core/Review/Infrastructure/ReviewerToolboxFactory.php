<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\Component\EventDispatcher\EventDispatcher;

final readonly class ReviewerToolboxFactory
{
    // The in-process twin of the MCP toolbox, kept so a test proves both give a review the same verdict path.
    public function create(ReviewRequest $request): Toolbox
    {
        return $this->inCheckout($request->checkout);
    }

    // Built per checkout because the tools and the listener hold the head under review.
    public function inCheckout(Checkout $checkout): Toolbox
    {
        // One resolver for the listener and the toolbox, so a required argument is known in one place.
        $arguments = new ToolCallArgumentResolver();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, new DenyPathsOutsideCheckout($checkout, $arguments));

        return new Toolbox(
            [new ReadFile($checkout), new ListDirectory($checkout), new SearchFiles($checkout)],
            argumentResolver: $arguments,
            eventDispatcher: $dispatcher,
        );
    }
}
