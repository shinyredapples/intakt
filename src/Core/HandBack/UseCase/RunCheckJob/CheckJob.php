<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Contract\HandBack;

final readonly class CheckJob
{
    /**
     * @param list<string> $namedByThePlan
     */
    public function __construct(
        public HandBack $handBack,
        public string $image,
        public ?string $configuredCommand,
        // The paths the task's plan names as removed or rewritten, so their loss of content is meant.
        public array $namedByThePlan,
    ) {
    }
}
