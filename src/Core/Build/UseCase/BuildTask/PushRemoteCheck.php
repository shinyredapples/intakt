<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Plugin\GitHubForge\Contract\ConfiguredRepositories;
use Uri\Rfc3986\Uri;
use Uri\UriException;

// Because the ownership check and the pull request read the configured repository while git pushes to the remote, and the two must name the same one.
final readonly class PushRemoteCheck
{
    public function __construct(
        private ConfiguredRepositories $repositories,
    ) {
    }

    // Any remote passes a project without a `github` entry, because it has no forge side to disagree with.
    public function failureOf(BuildTaskRequest $request): ?string
    {
        $configured = $this->repositories->nameOf($request->project);

        if (null === $configured) {
            return null;
        }

        if (strtolower($configured) === strtolower($this->repositoryOf($request->remote) ?? '')) {
            return null;
        }

        return sprintf('the remote %s is not the configured GitHub repository "%s" of project "%s"; pass its https://github.com/%s URL without credentials', $this->shown($request->remote), $configured, $request->project, $configured);
    }

    // Without the user info, query and fragment, because a remote can carry a token there and the refusal reaches the terminal and the logs. A remote without a host is shown only when it holds no "@" or ":", because an ssh address such as "user:token@host:path" parses as a scheme and a path that would show the token.
    private function shown(string $remote): string
    {
        try {
            $uri = new Uri($remote);
        } catch (UriException) {
            return '(not shown: it may hold credentials)';
        }

        if (null === $uri->getHost() && 1 === preg_match('/[@:]/', $remote)) {
            return '(not shown: it may hold credentials)';
        }

        $hidden = null !== $uri->getUserInfo() || null !== $uri->getQuery() || null !== $uri->getFragment();
        $shown = $uri->withUserInfo(null)->withQuery(null)->withFragment(null)->toRawString();

        return sprintf('"%s"%s', $shown, $hidden ? ' (credentials, query or fragment not shown)' : '');
    }

    // Only the https form is read, because that is the address the forge side names; any other form cannot be tied to it, so it reads as no repository.
    private function repositoryOf(string $remote): ?string
    {
        try {
            $uri = new Uri($remote);
        } catch (UriException) {
            return null;
        }

        if ('https' !== strtolower((string) $uri->getScheme()) || 'github.com' !== strtolower((string) $uri->getHost()) || null !== $uri->getUserInfo() || null !== $uri->getPort() || null !== $uri->getQuery() || null !== $uri->getFragment()) {
            return null;
        }

        $segments = explode('/', trim($uri->getPath(), '/'));

        if (2 !== count($segments) || '' === $segments[0]) {
            return null;
        }

        $name = preg_replace('/\.git$/i', '', $segments[1]);

        return '' === $name ? null : $segments[0].'/'.$name;
    }
}
