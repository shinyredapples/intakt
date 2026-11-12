<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\ErrorEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// Needed because DeferredResult::onError fires only while the result is converted, not while its stream is read; excluded from the container because one is built per stream.
#[Exclude]
final class AccountStreamFailures extends AbstractStreamListener
{
    /**
     * @param Closure(Throwable): void $failed
     */
    public function __construct(private readonly Closure $failed)
    {
    }

    #[Override]
    public function onError(ErrorEvent $event): void
    {
        ($this->failed)($event->getError());
    }
}
