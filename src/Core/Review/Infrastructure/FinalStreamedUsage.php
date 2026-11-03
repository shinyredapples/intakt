<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Override;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\Stream\ErrorEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Needed because Symfony AI adds up every usage event of a stream, while the last one already holds the whole call, so the sum would count the call more than once; excluded from the container because one is built per stream.
#[Exclude]
final class FinalStreamedUsage extends AbstractStreamListener
{
    private const string TOKEN_USAGE = 'token_usage';

    private ?TokenUsageInterface $final = null;

    #[Override]
    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if ($delta instanceof TokenUsageInterface) {
            $this->final = $delta;
        }
    }

    #[Override]
    public function onComplete(CompleteEvent $event): void
    {
        $this->settle($event->getResult());
    }

    // Also on a cut-off stream, because the tokens it reported were spent and the vendor leaves them unsettled.
    #[Override]
    public function onError(ErrorEvent $event): void
    {
        $this->settle($event->getResult());
    }

    private function settle(StreamResult $stream): void
    {
        if (!$this->final instanceof TokenUsageInterface) {
            return;
        }

        $stream->getMetadata()->remove(self::TOKEN_USAGE);
        $stream->getMetadata()->add(self::TOKEN_USAGE, $this->final);
    }
}
