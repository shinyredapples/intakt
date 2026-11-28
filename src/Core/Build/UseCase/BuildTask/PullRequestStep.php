<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

// Through the Git module's contract, because only it knows which head it pushed and records the pull request against that head; the forge writes as Intakt's GitHub App.
final readonly class PullRequestStep
{
    public function __construct(
        private BranchPush $branches,
        private BuildCheckout $checkout,
    ) {
    }

    public function failureOf(BuildTaskRequest $request, AcceptedChange $change, PushedBranch $pushed): ?string
    {
        try {
            $this->branches->openChange($this->pushOf($request, $change), $pushed);
        } catch (BuildCheckoutFailed|BranchNotPushed|ChangeTextHoldsSecret|ForgeWriteFailed $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    // The same request as the push step's, because the pull request carries its text and the Git module checks its head under the same branch lock.
    private function pushOf(BuildTaskRequest $request, AcceptedChange $change): BranchPushRequest
    {
        return new BranchPushRequest($request->project, $this->checkout->mirrorOf($request->project), $request->remote, $request->branch, $change->accepted, $change->text);
    }
}
