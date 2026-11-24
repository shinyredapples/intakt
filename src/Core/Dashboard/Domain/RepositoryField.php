<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Uri\Rfc3986\Uri;

// The repository address of the wizard's first step, checked on the server because the page must say what is wrong without a script; nothing keeps it yet.
#[Exclude]
final readonly class RepositoryField
{
    public const string EMPTY = 'Paste the repository address.';
    public const string NOT_GITHUB = 'Only a repository on GitHub can be connected.';
    public const string NOT_AN_ADDRESS = 'This is not a repository address: use https://github.com/owner/name or owner/name.';

    private const array GITHUB_HOSTS = ['github.com', 'www.github.com'];
    // GitHub's own naming limits, because a name GitHub would refuse should fail here and not at the first call to GitHub.
    private const string OWNER = '[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}';
    private const string NAME = '[A-Za-z0-9._-]{1,100}';

    private function __construct(
        public string $input,
        public ?string $repository,
        public ?string $error,
    ) {
    }

    public static function unsubmitted(): self
    {
        return new self('', null, null);
    }

    public static function submitted(string $input): self
    {
        $address = trim($input);

        if ('' === $address) {
            return new self($input, null, self::EMPTY);
        }

        $path = str_contains($address, '://') ? self::pathOfUrl($address) : $address;

        if (null === $path) {
            return new self($input, null, self::NOT_GITHUB);
        }

        $repository = self::repositoryIn($path);

        return null === $repository ? new self($input, null, self::NOT_AN_ADDRESS) : new self($input, $repository, null);
    }

    public function wasChecked(): bool
    {
        return null !== $this->repository || null !== $this->error;
    }

    // An address that is no https URL on GitHub gives an empty path, so it fails as no address at all; another host gives null.
    // Only the one leading slash and one trailing slash are dropped, so an empty segment such as github.com//owner/name stays wrong.
    private static function pathOfUrl(string $address): ?string
    {
        $url = Uri::parse($address);

        if (!$url instanceof Uri || 'https' !== $url->getScheme() || null !== $url->getUserInfo() || null !== $url->getPort() || null !== $url->getQuery() || null !== $url->getFragment()) {
            return '';
        }

        if (!in_array($url->getHost(), self::GITHUB_HOSTS, true)) {
            return null;
        }

        $path = $url->getPath();

        if (!str_starts_with($path, '/')) {
            return '';
        }

        return str_ends_with($path, '/') ? substr($path, 1, -1) : substr($path, 1);
    }

    // The clone suffix is dropped before the name is matched, because GitHub's 100-character limit counts the name without it.
    private static function repositoryIn(string $path): ?string
    {
        $bare = str_ends_with($path, '.git') ? substr($path, 0, -4) : $path;

        if (1 !== preg_match('~\A('.self::OWNER.')/('.self::NAME.')\z~', $bare, $parts)) {
            return null;
        }

        return in_array($parts[2], ['.', '..'], true) ? null : $parts[1].'/'.$parts[2];
    }
}
