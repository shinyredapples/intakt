<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Git\Contract\ArchivedHead;
use Intakt\Core\Git\Contract\ArchivedHeadPush;
use Intakt\Core\Git\Contract\RemoteCredential;
use LogicException;
use SensitiveParameter;

// The real push with GitHub's address swapped for a repository on disk, because github.com is not reachable from a test; the address and the credential the forge hands over are checked first.
final readonly class GitHubAddressOnDisk implements ArchivedHeadPush
{
    public function __construct(
        private ArchivedHeadPush $push,
        private string $address,
        private string $token,
        private string $repository,
    ) {
    }

    public function push(ArchivedHead $head, #[SensitiveParameter] string $remote, ?RemoteCredential $credential = null): void
    {
        if ($remote !== $this->address) {
            throw new LogicException(sprintf('The forge pushed to %s instead of %s.', $remote, $this->address));
        }

        if ($credential?->authorizationHeader() !== 'Authorization: Basic '.base64_encode('x-access-token:'.$this->token)) {
            throw new LogicException('The forge handed over no x-access-token login with the installation token.');
        }

        $this->push->push($head, $this->repository, $credential);
    }
}
