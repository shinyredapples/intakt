<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobOutput
{
    /**
     * @param array<string, string> $files contents by file name
     */
    public function __construct(private array $files)
    {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->files));
    }

    public function contents(string $name): ?string
    {
        return $this->files[$name] ?? null;
    }
}
