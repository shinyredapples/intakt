<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
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

    // The accepted hand-back for the push, otherwise the refusal's fixed text or the failure's message, never anything of the bundle, because what the build handed back is untrusted.
    public function check(BuildTaskRequest $request, string $bundle): AcceptedHandBack|string
    {
        return $this->files->check($request, $bundle, fn (HandBack $handBack): AcceptedHandBack|string => $this->acceptedOf($request, $handBack));
    }

    private function acceptedOf(BuildTaskRequest $request, HandBack $handBack): AcceptedHandBack|string
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
        } catch (BuildCheckoutFailed|InvalidArgumentException|HandBackBranchRefused|HandBackNotChecked|RepositoryAttributesRefused $failure) {
            return $failure->getMessage();
        }

        return $result;
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
