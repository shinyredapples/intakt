<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Uri\Rfc3986\Uri;

final readonly class PullRequestAddress
{
    // An owner has no dots and a repository is not only dots, so `.` or `..` cannot lead the token outside the repository's API path.
    private const string PATH = '#^/([A-Za-z0-9-]+)/(?!\.+/)([A-Za-z0-9_.-]+)/pull/([1-9][0-9]*)/?$#';

    private function __construct(
        public string $owner,
        public string $repository,
        public int $number,
    ) {
    }

    /**
     * @throws ChangeNotFound
     */
    public static function of(ChangeId $id): self
    {
        $uri = Uri::parse(trim($id->value));

        // Only github.com without credentials or a port, because the token must not follow a crafted address to another host.
        if (!$uri instanceof Uri || 'https' !== $uri->getScheme() || 'github.com' !== $uri->getHost() || null !== $uri->getUserInfo() || null !== $uri->getPort()) {
            throw ChangeNotFound::named($id);
        }

        if (1 !== preg_match(self::PATH, $uri->getRawPath(), $match)) {
            throw ChangeNotFound::named($id);
        }

        return new self($match[1], $match[2], (int) $match[3]);
    }

    // Without regard to case, because GitHub treats owner and repository names so and a pull request's address may spell them unlike the configuration.
    public function isIn(RepositoryAddress $repository): bool
    {
        return 0 === strcasecmp($this->owner, $repository->owner()) && 0 === strcasecmp($this->repository, $repository->name());
    }

    public function repositoryPath(): string
    {
        return '/repos/'.rawurlencode($this->owner).'/'.rawurlencode($this->repository);
    }

    public function pullRequestPath(): string
    {
        return $this->repositoryPath().'/pulls/'.$this->number;
    }

    // GitHub keeps a pull request's conversation comments under its issue number.
    public function issuePath(): string
    {
        return $this->repositoryPath().'/issues/'.$this->number;
    }
}
