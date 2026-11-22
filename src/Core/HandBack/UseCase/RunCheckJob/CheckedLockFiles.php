<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;

// The lock files of the tree the check job installs in, read before the job, so the key names what that tree's install depends on.
final readonly class CheckedLockFiles
{
    public function __construct(private RepositoryGit $git)
    {
    }

    // Null when the merged tree's files cannot be known before the merge, or one is missing, so the check installs itself.
    /**
     * @param non-empty-list<string> $paths
     *
     * @return ?non-empty-array<string, string>
     *
     * @throws HandBackNotChecked
     */
    public function of(string $repository, string $head, array $paths): ?array
    {
        $main = $this->git->answer($repository, ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']) ?? '';
        // Main's file must match, because the job checks a branch that lacks main on its merge with main, which holds the head's file only where main's is the same.
        $holdsMain = '' === $main || $this->git->succeeds($repository, ['merge-base', '--is-ancestor', $main, $head]);
        $files = [];

        foreach ($paths as $path) {
            $blob = $this->blobAt($repository, $head, $path);

            if (null === $blob || (!$holdsMain && $blob !== $this->blobAt($repository, $main, $path))) {
                return null;
            }

            $files[$path] = $this->git->exactOutput($repository, ['cat-file', 'blob', $blob]);
        }

        return $files;
    }

    // A plain file only, because the job would read a link's target, which the commit does not hold.
    private function blobAt(string $repository, string $commit, string $path): ?string
    {
        $entry = $this->git->answer($repository, ['ls-tree', '-z', '--full-tree', $commit, '--', $path]) ?? '';

        if (1 !== preg_match('/\A100(?:644|755) blob ([0-9a-f]{40,64})\t/', $entry, $match)) {
            return null;
        }

        return $match[1];
    }
}
