<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

final readonly class LockedPackage
{
    // Composer reads an entry without a type as a library, so the type rules do too.
    private const string DEFAULT_TYPE = 'library';

    // A release tag: no branch (dev-main, 1.x-dev), and no character git or the command line would read otherwise.
    private const string TAG = '/\A[A-Za-z0-9][A-Za-z0-9._+-]*\z/';

    /**
     * @param list<mixed> $binaries
     * @param list<mixed> $autoloadFiles
     */
    private function __construct(
        public string $name,
        public string $version,
        public PackageGroup $group,
        public string $type,
        public array $binaries,
        public array $autoloadFiles,
        public ?GitHubCommit $commit,
    ) {
    }

    /**
     * @throws ComposerFileUnreadable
     */
    public static function fromLockEntry(mixed $entry, PackageGroup $group): self
    {
        if (!is_array($entry) || !is_string($entry['name'] ?? null) || !is_string($entry['version'] ?? null)) {
            throw ComposerFileUnreadable::because(ComposerState::LOCK, 'a locked package has no name or version');
        }

        $type = $entry['type'] ?? self::DEFAULT_TYPE;
        $autoload = $entry['autoload'] ?? [];

        return new self(
            strtolower($entry['name']),
            $entry['version'],
            $group,
            is_string($type) ? $type : '',
            self::listed($entry['bin'] ?? []),
            self::listed(is_array($autoload) ? $autoload['files'] ?? [] : [$autoload]),
            GitHubCommit::fromLockEntry($entry),
        );
    }

    public function vendor(): string
    {
        return explode('/', $this->name, 2)[0];
    }

    public function isTagged(): bool
    {
        return 1 === preg_match(self::TAG, $this->version) && !str_starts_with(strtolower($this->version), 'dev-') && !str_ends_with(strtolower($this->version), '-dev');
    }

    // The group is left out, because a move between the groups is judged on its own.
    public function sameReleaseAs(self $other): bool
    {
        return $this->version === $other->version && GitHubCommit::same($this->commit, $other->commit) && $this->sameEffectsAs($other);
    }

    public function sameEffectsAs(self $other): bool
    {
        return $this->type === $other->type && $this->binaries === $other->binaries && $this->autoloadFiles === $other->autoloadFiles;
    }

    /**
     * @return list<mixed>
     */
    private static function listed(mixed $value): array
    {
        // A single value counts as one entry, because Composer accepts a string where it expects a list.
        return is_array($value) ? array_values($value) : [$value];
    }
}
