<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ApprovePush;

use Intakt\Plugin\GitHubForge\Domain\CiScanResult;

final readonly class PushOutcome
{
    public function __construct(
        public PushDecision $decision,
        public string $commit,
        public CiScanResult $scan,
    ) {
    }
}
