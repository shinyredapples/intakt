<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ChildCommand
{
    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    public function __construct(
        public array $command,
        public string $directory,
        public array $environment,
    ) {
    }
}
