<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowFile
{
    // A null content is a file that could not be read, which the scan reports as risky instead of skipping.
    public function __construct(
        public string $path,
        public ?string $content,
    ) {
    }
}
