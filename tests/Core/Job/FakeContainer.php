<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

final class FakeContainer
{
    public bool $running = false;
    public bool $memoryKilled = false;
    public int $exitCode = 0;
    public string $startCheckMark = '';

    /** @var list<string> */
    public array $networks = [];

    public function __construct(
        public readonly string $name,
        public readonly string $job,
        public readonly string $outputDirectory,
    ) {
    }
}
