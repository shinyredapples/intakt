<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeState;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncedListing;
use Intakt\Plugin\GitHubWorkSource\Domain\ValidatorChange;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Psr\Log\LoggerInterface;
use Throwable;

// One complete listing per tick and nothing read per reference, because routine re-reads used up the forge budget the essential steps need (docs/design/05-architecture/03-choosing-work.md, "Waits and intake read from the one listing").
final readonly class CompleteListing
{
    public function __construct(
        private IssueReads $reads,
        private IssueHistories $histories,
        private SteeringAccounts $accounts,
        private IntakeStore $store,
        private IssueReferences $references,
        private LoggerInterface $logger,
    ) {
    }

    // The rules come from the caller, because which label makes a task eligible is the project's setting and decides whose history is read.
    public function sync(string $project, IntakeRules $rules): SyncedListing
    {
        try {
            $issues = $this->recorded($project, $this->store->stateOf($project), $rules);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked|IntakeStoreLocked $short) {
            return $this->short($project, $short);
        }

        return SyncedListing::complete(array_map(static fn (StoredIssue $issue): QueuedIssue => $issue->queued(), $issues));
    }

    /**
     * @return list<StoredIssue>
     */
    private function recorded(string $project, IntakeState $seen, IntakeRules $rules): array
    {
        $listing = $this->reads->listOpen($project, $seen->validator);
        try {
            // A listing another sync has superseded already may show items that closed since, so no history is read for it.
            $issues = $this->isSuperseded($project, $seen) ? null : $this->vouched($project, $this->issuesOf($project, $listing), $rules);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $short) {
            // A read for a listing that was superseded meanwhile must not end the tick short, because the rows that now count were never asked.
            $issues = $this->isSuperseded($project, $seen) ? null : throw $short;
        }

        if (null !== $issues && $this->store->recordIfStill($project, $issues, new ValidatorChange($seen, $listing->isUnchanged() ? $seen->validator : $listing->validator))) {
            return $issues;
        }

        // The sync that stored its listing while this request was in flight holds the newer rows, but it vouched them under its own rules, so this tick's eligibility label is applied to them again.
        return $this->vouched($project, $this->store->issuesOf($project), $rules);
    }

    private function isSuperseded(string $project, IntakeState $seen): bool
    {
        return $this->store->stateOf($project)->generation !== $seen->generation;
    }

    // Every listed field comes from the listing, also for an unchanged item, because a milestone's due date changes without the item's update time moving.
    /**
     * @return list<StoredIssue>
     */
    private function issuesOf(string $project, IssueListing $listing): array
    {
        $stored = [];
        foreach ($this->store->issuesOf($project) as $issue) {
            $stored[$issue->number()] = $issue;
        }

        if ($listing->isUnchanged()) {
            return array_values($stored);
        }

        return array_map(fn (ListedIssue $listed): StoredIssue => $this->fresh($project, $listed, $stored[$listed->number] ?? null), $listing->issues());
    }

    private function fresh(string $project, ListedIssue $listed, ?StoredIssue $stored): StoredIssue
    {
        $body = $listed->body;
        $issue = StoredIssue::of($project, new ItemReading($listed, $this->references->waitsFor($body), $this->references->unreadableWaits($body), $this->references->closedBy($body)));
        $vouching = $stored?->vouchingWhileUnchanged($listed);
        if ($vouching instanceof Vouching) {
            $issue->vouch($vouching);
        }

        return $issue;
    }

    // Asked of every row on every tick, because a change of the eligibility label makes items candidates whose update time did not move.
    /**
     * @param list<StoredIssue> $issues
     *
     * @return list<StoredIssue>
     */
    private function vouched(string $project, array $issues, IntakeRules $rules): array
    {
        $needing = array_map(static fn (QueuedIssue $item): int => $item->number(), $rules->itemsNeedingHistory(array_map(static fn (StoredIssue $issue): QueuedIssue => $issue->queued(), $issues)));
        $steering = [];
        $steers = function (string $account) use ($project, &$steering): bool {
            return $steering[$account] ??= $this->accounts->steers($project, $account);
        };

        foreach ($issues as $issue) {
            if (!$issue->isVouched() && in_array($issue->number(), $needing, true)) {
                $issue->vouch(Vouching::of($issue->author(), $this->histories->of($project, $issue->number()), $steers));
            }
        }

        return $issues;
    }

    private function short(string $project, Throwable $short): SyncedListing
    {
        $this->logger->warning('The listing of project {project} came back short, so nothing is picked and no wait closes this tick: {reason}', ['project' => $project, 'reason' => $short->getMessage(), 'exception' => $short]);

        return SyncedListing::short($short->getMessage());
    }
}
