<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\MainConflicts;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Contract\RefusedPush;
use Intakt\Core\Git\Contract\UnsignedCommitRefused;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

// The signed push of the checked head through the Git module's contract, because only the orchestrator's signature may reach the remote branch; the text goes along because PullRequestStep opens the pull request of exactly this request.
final readonly class PushStep
{
    public function __construct(
        private BranchPush $branches,
        private BuildCheckout $checkout,
    ) {
    }

    // The pushed branch on success, because the pull request step opens the pull request of exactly that head; otherwise the failure's text.
    public function outcomeOf(BuildTaskRequest $request, AcceptedChange $change): PushedBranch|string
    {
        try {
            $outcome = $this->branches->push($this->pushOf($request, $change));
        } catch (BuildCheckoutFailed|BranchNotPushed|UnsignedCommitRefused|ForgeWriteFailed $failure) {
            return $failure->getMessage();
        }

        return $outcome instanceof PushedBranch ? $outcome : $this->failureIn($outcome);
    }

    // The mirror, because the hand-back check left the accepted head's objects there and the signing writes its commits there.
    private function pushOf(BuildTaskRequest $request, AcceptedChange $change): BranchPushRequest
    {
        return new BranchPushRequest($request->project, $this->checkout->mirrorOf($request->project), $request->remote, $request->branch, $change->accepted, $change->text);
    }

    // Only the count of conflicting paths, because the paths are names the builder chose.
    private function failureIn(MainConflicts|RefusedPush $outcome): string
    {
        return match (true) {
            $outcome instanceof RefusedPush => $outcome->reason,
            $outcome instanceof MainConflicts => sprintf('the branch conflicts with main, so nothing was pushed (conflicting paths: %d)', iterator_count($outcome->paths)),
        };
    }
}
