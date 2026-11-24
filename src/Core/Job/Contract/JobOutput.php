<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobOutput
{
    /** @var list<JobOutputFile> */
    private array $files;

    public function __construct(JobOutputFile ...$files)
    {
        $byName = [];
        foreach ($files as $file) {
            $byName[$file->name] = $file;
        }

        $this->files = array_values($byName);
    }

    /**
     * @return list<JobOutputFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function contents(string $name): ?string
    {
        return array_find($this->files, static fn (JobOutputFile $file): bool => $name === $file->name)?->contents;
    }
}
