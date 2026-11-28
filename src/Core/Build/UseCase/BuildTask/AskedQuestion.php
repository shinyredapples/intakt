<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\RegressionRuns;

// The question a build ends with, the cause its session was stopped for, if any, and the tokens it used, because the parked or refused row of the build job names all three.
final readonly class AskedQuestion
{
    public function __construct(
        public BuildQuestion $question,
        public ?BuildStopCause $stopCause = null,
        public int $tokens = 0,
    ) {
    }

    public static function in(BuildJobOutcome|BuildQuestion|EndedStep|AcceptedHandBack|RegressionRuns|string|null $result): ?self
    {
        if ($result instanceof BuildQuestion) {
            return new self($result);
        }

        return $result instanceof BuildJobOutcome && $result->question instanceof BuildQuestion ? new self($result->question, $result->stopCause, $result->tokens) : null;
    }
}
