<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Infrastructure;

use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\TakeCheckedHead\TakeCheckedHead;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Override;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockFailure;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessFailure;

final readonly class GitBuildCheckout implements BuildCheckout
{
    public function __construct(
        private UpdateMirror $mirrors,
        private MakeBuildClone $clones,
        private Mirrors $mirrorPaths,
        private TakeCheckedHead $heads,
    ) {
    }

    #[Override]
    public function updateMirror(string $project, string $remote): void
    {
        try {
            $this->mirrors->update($project, $remote);
        } catch (ProcessFailure $stopped) {
            throw BuildCheckoutFailed::stopped($stopped);
        } catch (IOException|RepositoryAttributesRefused|LockFailure|ReportableFailure $failure) {
            throw BuildCheckoutFailed::because($failure);
        }
    }

    #[Override]
    public function makeClone(string $project, string $branch, string $directory): void
    {
        try {
            $this->clones->make($project, new TaskBranch($branch), $directory);
        } catch (ProcessFailure $stopped) {
            throw BuildCheckoutFailed::stopped($stopped);
        } catch (IOException|RepositoryAttributesRefused|ReportableFailure $failure) {
            throw BuildCheckoutFailed::because($failure);
        }
    }

    #[Override]
    public function takeCheckedHead(string $project, BranchHead $head, string $directory): void
    {
        try {
            $this->heads->take($project, $head, $directory);
        } catch (ProcessFailure $stopped) {
            throw BuildCheckoutFailed::stopped($stopped);
        } catch (RepositoryAttributesRefused|ReportableFailure $failure) {
            throw BuildCheckoutFailed::because($failure);
        }
    }

    #[Override]
    public function mirrorOf(string $project): string
    {
        try {
            return $this->mirrorPaths->pathOf($project);
        } catch (ProjectNameRefused $failure) {
            throw BuildCheckoutFailed::because($failure);
        }
    }
}
