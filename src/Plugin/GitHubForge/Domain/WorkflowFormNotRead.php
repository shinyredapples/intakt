<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use RuntimeException;

final class WorkflowFormNotRead extends RuntimeException
{
    public function __construct(
        public readonly int $lineNumber,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}
