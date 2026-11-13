<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

final readonly class LockedPackage
{
    // Composer reads an entry without a type as a library, so the type rules do too.
    private const string DEFAULT_TYPE = 'library';

    // A release tag: no branch (dev-main, 1.x-dev), and no character git or the command line would read otherwise.
    private const string TAG = '/\A[A-Za-z0-9][A-Za-z0-9._+-]*\z/';

    // Every key Composer downloads the code by, so a release with any of them changed is judged again.
    private const array PLACES = ['source', 'dist', 'transport-options'];

    /**
     * @param list<mixed>          $binaries
     * @param array<string, mixed> $places
     */
    private function __construct(
        public string $name,
        public string $version,
        public PackageGroup $group,
        public string $type,
        public array $binaries,
        public mixed $autoload,
        public array $places,
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

        return new self(
            strtolower($entry['name']),
            $entry['version'],
            $group,
            is_string($type) ? $type : '',
            self::listed($entry['bin'] ?? []),
            $entry['autoload'] ?? [],
            array_intersect_key($entry, array_flip(self::PLACES)),
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

    // The vendor's own top-level namespace, because a package mapping any other one could replace the project's classes or another package's.
    public function ownsNamespace(string $namespace): bool
    {
        $topLevel = strstr($namespace, '\\', true);

        return false !== $topLevel && strtolower($topLevel) === $this->vendor();
    }

    // The group is left out, because a move between the groups is judged on its own; the places are compared as written, because an uncheckable place has no commit to compare.
    public function sameReleaseAs(self $other): bool
    {
        return $this->version === $other->version && $this->places === $other->places && $this->sameEffectsAs($other);
    }

    public function sameEffectsAs(self $other): bool
    {
        return $this->type === $other->type && $this->binaries === $other->binaries && $this->autoload === $other->autoload;
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
