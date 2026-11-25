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

    // The token as the password of the https address, the form GitHub takes from an App's installation; the address is never shown, because it holds the token.
    public function pushRemote(string $token): string
    {
        return sprintf('https://x-access-token:%s@github.com/%s/%s.git', rawurlencode($token), rawurlencode($this->owner), rawurlencode($this->repository));
    }

    // GitHub's filter for a branch of this repository, because a fork's branch of the same name must not match it.
    public function headOf(string $branch): string
    {
        return $this->owner.':'.$branch;
    }

    // The same address PullRequestAddress reads back, so a change listed here can be read by the forge's other methods.
    public function pullRequest(int $number): ChangeId
    {
        return new ChangeId(sprintf('https://github.com/%s/%s/pull/%d', $this->owner, $this->repository, $number));
    }
}
