<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Infrastructure;

use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;
use Override;
use Symfony\Component\Filesystem\Exception\IOException;

final readonly class GitBuildCheckout implements BuildCheckout
{
    public function __construct(
        private UpdateMirror $mirrors,
        private MakeBuildClone $clones,
    ) {
    }

    #[Override]
    public function updateMirror(string $project, string $remote): void
    {
        try {
            $this->mirrors->update($project, $remote);
        } catch (InvalidArgumentException|ReportableFailure $failure) {
            throw BuildCheckoutFailed::because($failure);
        }
    }

    #[Override]
    public function makeClone(string $project, string $branch, string $directory): void
    {
        try {
            $this->clones->make($project, new TaskBranch($branch), $directory);
        } catch (InvalidArgumentException|IOException|RepositoryAttributesRefused|ReportableFailure $failure) {
            throw BuildCheckoutFailed::because($failure);
        }
    }
}
