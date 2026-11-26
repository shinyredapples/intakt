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
        $uri = Uri::parse(trim($address));

        if (!$uri instanceof Uri || 'https' !== $uri->getScheme() || 'github.com' !== $uri->getHost() || null !== $uri->getUserInfo() || null !== $uri->getPort()) {
            return $address;
        }

        if (1 !== preg_match(self::PULL_REQUEST_PATH, $uri->getRawPath(), $match)) {
            return $address;
        }

        return sprintf('https://github.com/%s/%s/pull/%s', strtolower($match[1]), strtolower($match[2]), $match[3]);
    }

    // Null unless the address is a GitHub pull request, so a caller can narrow a read to the spellings that hold the number.
    public static function pullRequestNumber(string $address): ?string
    {
        $canonical = self::canonical($address);

        $path = Uri::parse($canonical);

        if (!$path instanceof Uri || 'github.com' !== $path->getHost()) {
            return null;
        }

        return 1 === preg_match(self::PULL_REQUEST_PATH, $path->getRawPath(), $match) ? $match[3] : null;
    }

    public static function sameChange(string $one, string $other): bool
    {
        return self::canonical($one) === self::canonical($other);
    }
}
