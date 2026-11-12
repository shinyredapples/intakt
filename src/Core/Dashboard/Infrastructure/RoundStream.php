<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

enum RoundStream: string
{
    case ToolCalled = 'tool_called';
    case TextStreamed = 'text_streamed';
    case SummaryChanged = 'summary_changed';
    case RoundChanged = 'round_changed';
    case ReviewerStopped = 'reviewer_stopped';

    public function template(): string
    {
        return sprintf('dashboard/stream/%s.stream.html.twig', $this->value);
    }
}
