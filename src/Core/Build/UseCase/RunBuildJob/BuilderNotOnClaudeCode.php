<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class BuilderNotOnClaudeCode extends RuntimeException implements ReportableFailure
{
    public function __construct(string $project, Tier $tier, Provider $provider)
    {
        parent::__construct(sprintf(
            'Project "%s" maps the "%s" tier to provider "%s", but a build job runs the Claude Code CLI and needs provider "%s".',
            $project,
            $tier->value,
            $provider->value,
            Provider::ClaudeCode->value,
        ));
    }
}
