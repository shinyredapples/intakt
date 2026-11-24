<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class LabelEvent
{
    public function __construct(
        public string $label,
        public string $actor,
        public bool $applied,
    ) {
    }
}
