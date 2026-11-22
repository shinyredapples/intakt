<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Throwable;

// Symfony AI's exception extended, so every caller that handles a cut stream still does, while the cut keeps what the stream showed before it ended: the message alone cannot tell the platform, the provider and Intakt's own check apart (issue #1964).
final class StreamEndedEarly extends IncompleteStreamException
{
    public function __construct(
        string $message,
        public readonly StreamCutBy $by,
        public readonly int $chunks,
        public readonly StreamChunks $lastChunks,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function described(): string
    {
        return sprintf(
            "cut by: %s\nchunks: %d\nlast chunks: %s",
            $this->by->value,
            $this->chunks,
            $this->lastChunks->isEmpty() ? 'none' : implode(', ', [...$this->lastChunks]),
        );
    }
}
