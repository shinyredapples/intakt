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

    // Not for an added file or a submodule, because the one had nothing to lose and the other's entry is a commit, not content.
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
