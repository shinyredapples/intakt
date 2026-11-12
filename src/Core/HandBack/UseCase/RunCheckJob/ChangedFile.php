<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

// One entry of "git diff --raw -z": the modes and blob ids before and after, the status letter and its paths.
final readonly class ChangedFile
{
    private const string GITLINK = '160000';

    /**
     * @param list<string> $paths
     */
    public function __construct(
        public string $status,
        public string $oldMode,
        public string $newMode,
        public string $oldBlob,
        public string $newBlob,
        public array $paths,
    ) {
    }

    // An added file had nothing to lose, and a submodule's entry is a commit, not content.
    public function couldLoseContent(): bool
    {
        return !$this->isAdded() && self::GITLINK !== $this->oldMode && self::GITLINK !== $this->newMode;
    }

    public function isRemoved(): bool
    {
        return str_starts_with($this->status, 'D');
    }

    /**
     * @param list<string> $paths
     */
    public function isNamedIn(array $paths): bool
    {
        return [] !== array_intersect($this->paths, $paths);
    }

    public function path(): string
    {
        return (string) array_last($this->paths);
    }

    private function isAdded(): bool
    {
        return str_starts_with($this->status, 'A');
    }
}
