<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Uri\Rfc3986\Uri;

// Only an address the GitHub forge accepts as a pull request is rewritten, because GitHub does not tell owners or repositories apart by letter case, while git does tell local branches apart by it. The checks mirror GitHubForge's PullRequestAddress::of(), which the Review module may not use, and ChangeAddressTest holds the two together.
final readonly class ChangeAddress
{
    private const string PULL_REQUEST_PATH = '#^/([A-Za-z0-9-]+)/(?!\.+/)([A-Za-z0-9_.-]+)/pull/([1-9][0-9]*)/?$#D';

    // Reduced to owner, repository and number, because the forge reads only those, so a fragment, a query, a trailing slash or surrounding white space names the same pull request.
    public static function canonical(string $address): string
    {
        $parts = self::pullRequestParts($address);

        if (null === $parts) {
            return $address;
        }

        return sprintf('https://github.com/%s/%s/pull/%s', strtolower($parts[1]), strtolower($parts[2]), $parts[3]);
    }

    // Every spelling that canonicalizes to a pull request holds this text literally in its raw path, because the path is matched unencoded, so a store can narrow its search to the addresses that hold it.
    public static function pullRequestPathOf(string $address): ?string
    {
        $parts = self::pullRequestParts($address);

        return null === $parts ? null : '/pull/'.$parts[3];
    }

    public static function sameChange(string $one, string $other): bool
    {
        return self::canonical($one) === self::canonical($other);
    }

    /**
     * @return ?array<int, string>
     */
    private static function pullRequestParts(string $address): ?array
    {
        $uri = Uri::parse(trim($address));

        if (!$uri instanceof Uri || 'https' !== $uri->getScheme() || 'github.com' !== $uri->getHost() || null !== $uri->getUserInfo() || null !== $uri->getPort()) {
            return null;
        }

        if (1 !== preg_match(self::PULL_REQUEST_PATH, $uri->getRawPath(), $match)) {
            return null;
        }

        return $match;
    }
}
