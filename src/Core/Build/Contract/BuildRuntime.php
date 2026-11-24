<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Ai\Contract\Provider;

// A build runs the Claude Code CLI, so the build guards and every view of who can build read the one provider from here.
final readonly class BuildRuntime
{
    public const Provider PROVIDER = Provider::ClaudeCode;
}
