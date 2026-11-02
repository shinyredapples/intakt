<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

// The reasoning efforts the Codex CLI accepts as model_reasoning_effort.
enum CodexEffort: string
{
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case ExtraHigh = 'xhigh';
}
