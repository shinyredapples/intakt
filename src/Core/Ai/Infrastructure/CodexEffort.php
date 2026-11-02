<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

// Only the values the Codex CLI accepts as model_reasoning_effort, so a typo fails at boot instead of in a call (https://developers.openai.com/codex/config-reference).
enum CodexEffort: string
{
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case ExtraHigh = 'xhigh';
}
