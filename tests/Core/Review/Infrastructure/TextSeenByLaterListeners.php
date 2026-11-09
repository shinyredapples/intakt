<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Override;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;

// Stands where Symfony AI's partial object listener sits, after StreamedAnswer, to show which text it gets.
final class TextSeenByLaterListeners extends AbstractStreamListener
{
    /**
     * @var list<string>
     */
    private array $seen = [];

    #[Override]
    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if ($delta instanceof TextDelta) {
            $this->seen[] = $delta->getText();
        }
    }

    /**
     * @return list<string>
     */
    public function seen(): array
    {
        return $this->seen;
    }
}
