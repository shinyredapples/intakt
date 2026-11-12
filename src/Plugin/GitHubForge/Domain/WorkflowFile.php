<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowFile
{
    // Null when the file could not be read, because the scan must report such a file as risky instead of skipping it.
    public function __construct(
        public string $path,
        public ?string $content,
    ) {
    }
}
