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

    // The keys a release rewrites without loading other code: its name and version, when it was made, what it needs and what it says about itself. The places are judged by the tag check. Every other key of the entry is an effect.
    private const array RELEASE_DATA = ['name', 'version', 'version_normalized', 'time', 'published-time', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'description', 'keywords', 'homepage', 'license', 'authors', 'support', 'funding', 'notification-url', 'readme', 'abandoned', 'default-branch', 'archive', 'non-feature-branches', '_comment'];

    // The alias follows the version (3.8-dev, then 3.9-dev), so comparing it would send every minor update to the owner.
    private const string BRANCH_ALIAS = 'branch-alias';

    /**
     * @param list<mixed>          $binaries
     * @param list<mixed>          $includePaths
     * @param array<string, mixed> $places
     * @param array<string, mixed> $effects
     */
    private function __construct(
        public string $name,
        public string $version,
        public PackageGroup $group,
        public string $type,
        public array $binaries,
        public mixed $autoload,
        public array $includePaths,
        public mixed $targetDir,
        public array $places,
        public array $effects,
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
            self::listed($entry['include-path'] ?? []),
            $entry['target-dir'] ?? null,
            array_intersect_key($entry, array_flip(self::PLACES)),
            self::effects($entry),
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
        return $this->version === $other->version && $this->places === $other->places && [] === $this->effectsChangedSince($other);
    }

    /**
     * @return list<string>
     */
    public function effectsChangedSince(self $earlier): array
    {
        $keys = array_keys($this->effects + $earlier->effects);
        $changed = array_filter($keys, fn (int|string $key): bool => ($this->effects[$key] ?? null) !== ($earlier->effects[$key] ?? null));
        sort($changed);

        return array_map(strval(...), $changed);
    }

    /**
     * @return list<string>
     */
    public function effectKeys(): array
    {
        // Cast, because PHP turns a numeric key back into an integer.
        return array_map(strval(...), array_keys($this->effects));
    }

    /**
     * @return list<mixed>
     */
    private static function listed(mixed $value): array
    {
        // A single value counts as one entry, because Composer accepts a string where it expects a list.
        return is_array($value) ? array_values($value) : [$value];
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function effects(array $entry): array
    {
        $effects = [];
        foreach (array_diff_key($entry, array_flip([...self::RELEASE_DATA, ...self::PLACES])) as $key => $value) {
            $effects[(string) $key] = $value;
        }

        if (is_array($effects['extra'] ?? null)) {
            $extra = array_diff_key($effects['extra'], [self::BRANCH_ALIAS => true]);
            $effects = [] === $extra ? array_diff_key($effects, ['extra' => true]) : [...$effects, 'extra' => $extra];
        }

        return $effects;
    }
}
