<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Needed because a streamed answer is whole only once it was read to its end, long after DeferredResult::onConvert ran; excluded from the container because one is built per stream.
#[Exclude]
final class AccountStreamAnswered extends AbstractStreamListener
{
    /**
     * @param Closure(): void $answered
     */
    public function __construct(private readonly Closure $answered)
    {
    }

    #[Override]
    public function onComplete(CompleteEvent $event): void
    {
        ($this->answered)();
    }
}
