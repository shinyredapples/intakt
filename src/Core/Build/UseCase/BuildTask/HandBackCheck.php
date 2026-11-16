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
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

// An accepted head moves the clone's branch, because the job committed in a clone of its own.
final readonly class HandBackCheck
{
    public function __construct(
        private BuildCheckout $checkout,
        private HandBackChecks $checks,
        private HandBackFile $files,
    ) {
    }

    // The refusal's fixed text or the failure's message, never anything of the bundle, because what the build handed back is untrusted.
    public function failureOf(BuildTaskRequest $request, string $bundle): ?string
    {
        return $this->files->failureOf($request, $bundle, fn (HandBack $handBack): ?string => $this->refusalOf($request, $handBack));
    }

    private function refusalOf(BuildTaskRequest $request, HandBack $handBack): ?string
    {
        try {
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
