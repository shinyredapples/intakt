<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use NoDiscard;

// A check that cannot run (HandBackNotChecked) is the install's fault, not the build's, so it is thrown and never counts as a failure of the task.
final readonly class RouteHandBack
{
    public function __construct(
        private CheckHandBack $checks,
        private BundleSecretScan $secrets,
        private HandBackRoutes $routes,
    ) {
    }

    /**
     * @throws HandBackBranchRefused
     * @throws HandBackNotChecked
     * @throws RepositoryAttributesRefused
     * @throws HandBackRoundNotRecorded
     */
    #[NoDiscard]
    public function route(HandBack $handBack, string $pullRequestText): AcceptedHandBack|FailedHandBack
    {
        $checked = $this->checks->check($handBack);
        $repository = $this->resolved($handBack->repository);

        if ($checked instanceof RefusedHandBack) {
            return $this->routes->failed($handBack, $repository, $checked->refusal);
        }

        // After the bundle, because the check has found the repository the scan runs in by then.
        $refusal = $this->secrets->refusalOfText($pullRequestText, $repository, $handBack->mountedCredentials);

        if ($refusal instanceof HandBackRefusal) {
            return $this->routes->failed($handBack, $repository, $refusal);
        }

        $this->routes->accepted($handBack, $repository, $checked);

        return $checked;
    }

    private function resolved(string $repository): string
    {
        // The check resolved it already, so one task's lines match however its path was written.
        $path = realpath($repository);

        if (false === $path) {
            throw HandBackNotChecked::in($repository, 'it is gone');
        }

        return $path;
    }
}
