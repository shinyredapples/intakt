<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeId;

final readonly class RepositoryAddress
{
    public function __construct(
        private string $owner,
        private string $repository,
    ) {
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function name(): string
    {
        return $this->repository;
    }

    public function path(): string
    {
        return '/repos/'.rawurlencode($this->owner).'/'.rawurlencode($this->repository);
    }

    // The same address PullRequestAddress reads back, so a change listed here can be read by the forge's other methods.
    public function pullRequest(int $number): ChangeId
    {
        return new ChangeId(sprintf('https://github.com/%s/%s/pull/%d', $this->owner, $this->repository, $number));
    }
}
