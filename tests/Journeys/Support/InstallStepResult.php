<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

final readonly class InstallStepResult
{
    public function __construct(
        public string $name,
        public InstallStatus $status,
        public string $tail,
    ) {
    }

    public function line(): string
    {
        return str_pad($this->status->value, 8).$this->name;
    }
}
