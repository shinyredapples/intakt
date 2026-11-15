<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackChecks;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

// Checked against the project's mirror, because the check needs a bare repository whose HEAD names main, and the build clone's HEAD names the task's branch; an accepted head then moves the clone's branch, because the job committed in a clone of its own.
final readonly class HandBackCheck
{
    // Never asked, because a first build has no rework that asks for a merge of main.
    private const bool MERGE_OF_MAIN_ASKED = false;

    public function __construct(
        private BuildCheckout $checkout,
        private HandBackChecks $checks,
        private Filesystem $filesystem,
        // A file of its own for each run, beside the prompts, because the check reads a bundle from a path.
        #[Autowire('%kernel.project_dir%/var/hand-backs')]
        private string $bundleFolder,
    ) {
    }

    // The refusal's fixed text or the failure's message, never anything of the bundle, because what the build handed back is untrusted.
    public function failureOf(BuildTaskRequest $request, string $bundle): ?string
    {
        try {
            return $this->refusalOfWritten($request, $bundle);
        } catch (IOException $failure) {
            // Also after an accepted bundle, because a file that cannot be removed leaves the untrusted bundle on disk.
            return $failure->getMessage();
        }
    }

    private function refusalOfWritten(BuildTaskRequest $request, string $bundle): ?string
    {
        $file = $this->bundleFileOf($bundle);

        try {
            return $this->refusalOf($request, $file);
        } finally {
            $this->filesystem->remove($file);
        }
    }

    private function bundleFileOf(string $bundle): string
    {
        $this->filesystem->mkdir($this->bundleFolder, 0o700);
        $file = $this->filesystem->tempnam($this->bundleFolder, 'handback-', '.bundle');
        $this->filesystem->dumpFile($file, $bundle);

        return $file;
    }

    private function refusalOf(BuildTaskRequest $request, string $file): ?string
    {
        try {
            // No credentials, because the build job mounts none yet, so there is nothing to redact.
            $handBack = new HandBack($this->checkout->mirrorOf($request->project), $request->branch, $file, self::MERGE_OF_MAIN_ASKED, new JobCredentials());
            $result = $this->checks->check($handBack);

            if ($result instanceof RefusedHandBack) {
                return $result->reason();
            }

            $this->checkout->takeCheckedHead($request->project, new BranchHead($request->branch, $result->head), $request->directory);
        } catch (ExceptionInterface $stopped) {
            // Caught first, because a process failure may also be an InvalidArgumentException, and its message names the whole command line.
            return $this->stoppedBy($stopped);
        } catch (BuildCheckoutFailed|InvalidArgumentException|HandBackNotChecked|RepositoryAttributesRefused $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    private function stoppedBy(ExceptionInterface $stopped): string
    {
        return match (true) {
            $stopped instanceof ProcessTimedOutException => sprintf('the hand-back check timed out after %s seconds', $stopped->getExceededTimeout()),
            $stopped instanceof ProcessSignaledException => sprintf('the hand-back check was killed by signal %d', $stopped->getSignal()),
            default => 'the hand-back check could not run',
        };
    }
}
