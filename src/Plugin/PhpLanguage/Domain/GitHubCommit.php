<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

// One repository at one commit, because Composer fetches a locked package's code from GitHub by both.
final readonly class GitHubCommit
{
    // Only Composer's GitHub forms, because any other place could serve other code: the clone URL of the source and the zipball of the dist.
    private const string SOURCE = '~\Ahttps://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?\z~';

    private const string DIST = '~\Ahttps://api\.github\.com/repos/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/zipball/([0-9a-f]{40})\z~';

    private const string COMMIT = '/\A[0-9a-f]{40}\z/';

    // No other key, because Composer downloads from a preferred mirror before the URL, so only a place that names nothing else can be checked.
    private const array SOURCE_KEYS = ['type', 'url', 'reference'];

    private const array DIST_KEYS = ['type', 'url', 'reference', 'shasum'];

    private function __construct(
        public string $owner,
        public string $repository,
        public string $reference,
    ) {
    }

    // Null unless every place the entry names agrees on one GitHub repository and one commit, because Composer installs from the dist and falls back to the source.
    /**
     * @param array<mixed> $entry
     */
    public static function fromLockEntry(array $entry): ?self
    {
        // Its stream options could send the download through a proxy that serves other code.
        if (array_key_exists('transport-options', $entry)) {
            return null;
        }

        $places = array_filter([self::source($entry['source'] ?? null), self::dist($entry['dist'] ?? null)]);
        $keys = array_unique(array_map(static fn (self $place): string => $place->key(), $places));

        if (1 !== count($keys) || self::nowhere()->key() === array_first($keys)) {
            return null;
        }

        return array_first($places);
    }

    public function cloneUrl(): string
    {
        return sprintf('https://github.com/%s/%s.git', $this->owner, $this->repository);
    }

    public function sameRepositoryAs(self $other): bool
    {
        return strtolower($this->owner.'/'.$this->repository) === strtolower($other->owner.'/'.$other->repository);
    }

    private function key(): string
    {
        return strtolower($this->owner.'/'.$this->repository).'@'.$this->reference;
    }

    private static function source(mixed $source): ?self
    {
        if (null === $source) {
            return null;
        }

        if (!is_array($source) || [] !== array_diff_key($source, array_flip(self::SOURCE_KEYS)) || 'git' !== ($source['type'] ?? null) || !is_string($source['url'] ?? null) || !is_string($source['reference'] ?? null)
            || 1 !== preg_match(self::SOURCE, $source['url'], $match) || 1 !== preg_match(self::COMMIT, $source['reference'])) {
            return self::nowhere();
        }

        return new self($match[1], $match[2], $source['reference']);
    }

    private static function dist(mixed $dist): ?self
    {
        if (null === $dist) {
            return null;
        }

        if (!is_array($dist) || [] !== array_diff_key($dist, array_flip(self::DIST_KEYS)) || 'zip' !== ($dist['type'] ?? null) || !is_string($dist['url'] ?? null)
            || 1 !== preg_match(self::DIST, $dist['url'], $match) || $match[3] !== ($dist['reference'] ?? null)) {
            return self::nowhere();
        }

        return new self($match[1], $match[2], $match[3]);
    }

    // A place in any other form, which makes the whole entry uncheckable, because Composer could fetch the code from it.
    private static function nowhere(): self
    {
        return new self('', '', '');
    }
}
