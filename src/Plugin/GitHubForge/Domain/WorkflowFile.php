<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowFile
{
    // Null when the file could not be read, because such a workflow may name any path and must widen the CI definition instead of being skipped.
    public function __construct(
        public string $path,
        public ?string $content,
        public string $notReadBecause = '',
    ) {
    }

    public static function unread(string $path, string $reason): self
    {
        return new self($path, null, $reason);
    }
}
