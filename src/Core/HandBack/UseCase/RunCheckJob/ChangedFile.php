<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

// One entry of "git diff --raw -z", typed so the loss check reads the modes, blob ids, status and paths by name.
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

    // Not for an added file or a former submodule, because the one had nothing to lose and the other's entry was a commit, not content.
    public function couldLoseContent(): bool
    {
        return !$this->isAdded() && self::GITLINK !== $this->oldMode;
    }

    // A file a submodule replaced keeps none of its content, because the new entry is a commit id.
    public function isGone(): bool
    {
        return str_starts_with($this->status, 'D') || self::GITLINK === $this->newMode;
    }

    public function isRenamed(): bool
    {
        return str_starts_with($this->status, 'R');
    }

    public function movedTo(string $path): bool
    {
        return $this->isRenamed() && $this->path() === $path;
    }

    public function isCreatedAt(string $path): bool
    {
        return $this->isAdded() && $this->path() === $path;
    }

    // An added file and the new path of a rename or a copy, because those are the only entries whose path did not exist at the base.
    public function isNewAt(string $path): bool
    {
        return $this->path() === $path && ($this->isAdded() || 2 === count($this->paths));
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

    public function oldPath(): string
    {
        return (string) array_first($this->paths);
    }

    // One more than the paths, because "--raw -z" prints the status field before them.
    public function fieldCount(): int
    {
        return 1 + count($this->paths);
    }

    public function isAdded(): bool
    {
        return str_starts_with($this->status, 'A');
    }
}
