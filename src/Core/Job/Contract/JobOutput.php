<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// Keyed by file name, where PHP turns a name of digits into an integer key, so names() gives every name back as text.
final readonly class JobOutput
{
    /**
     * @param array<array-key, string> $files
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
