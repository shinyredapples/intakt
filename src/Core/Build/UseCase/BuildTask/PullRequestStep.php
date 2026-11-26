<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Plugin\Forge\Contract\ChangeText;
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

    public function failureOf(BuildTaskRequest $request, AcceptedHandBack $accepted, ChangeText $text): ?string
    {
        try {
            $this->branches->openChange($this->pushOf($request, $accepted, $text));
        } catch (BuildCheckoutFailed|BranchNotPushed|ChangeTextHoldsSecret|ForgeWriteFailed $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    // The same request as the push step's, because the Git module signs it again to find the head it pushed.
    private function pushOf(BuildTaskRequest $request, AcceptedHandBack $accepted, ChangeText $text): BranchPushRequest
    {
        return new BranchPushRequest($request->project, $this->checkout->mirrorOf($request->project), $request->remote, $request->branch, $accepted, $text);
    }
}
