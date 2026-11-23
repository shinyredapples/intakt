<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectNamesNoEligibilityLabel;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingReplacedMeanwhile;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\ProjectHeldByAnotherWriter;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncState;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use NoDiscard;
use Psr\Log\LoggerInterface;

// One complete listing per tick, every page read before anything is sorted (owner decision of 2026-11-11), and waits answered from its rows, because a read per waited-for reference would cost a call each and a short listing would pick the wrong task and close open waits.
final readonly class CompleteListing
{
    public function __construct(
        private IssueReads $reads,
        private IssueHistories $histories,
        private SteeringAccounts $accounts,
        private IntakeStore $store,
        private EligibilityLabels $labels,
        private IssueReferences $references,
        private LoggerInterface $logger,
    ) {
    }

    // Null when the listing came back short, so nothing is picked and no wait closes this tick.
    #[NoDiscard]
    public function planOf(string $project): ?IntakePlan
    {
        try {
            return $this->syncOf($project);
        } catch (ProjectHeldByAnotherWriter|ListingReplacedMeanwhile $held) {
            // A refusal before a won write, or a newer listing committed while a lost write's rows were checked, leaves nothing written, and a refusal of the plan's read after a won write keeps the rows written, which are the newest committed listing: either way only this tick's plan is skipped and the next tick plans from what is stored.
            return $this->shortListing($project, $held);
        }
    }

    private function syncOf(string $project): ?IntakePlan
    {
        $readBefore = $this->store->syncStateOf($project);
        try {
            $rules = $this->rulesOf($project);
            $listing = $this->reads->listOpen($project, $readBefore->validator);
            $rows = $this->rowsOf($project, $listing);
            $vouched = $this->vouchWhatSteers($project, $rows, $rules);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked|ProjectNamesNoEligibilityLabel $short) {
            return $this->shortListing($project, $short);
        }

        if ($listing->isUnchanged() && [] === $vouched) {
            return $this->planOver($project, $rows, $rules);
        }

        $listed = new ListingRows($rows, $listing->isUnchanged() ? $readBefore->validator : $listing->validator);

        return $this->store->recordIfStill($project, $readBefore, $listed) ? $this->planOver($project, $rows, $rules) : $this->planOverTheNewerRows($project, $rules);
    }

    // Each project names its own eligibility label, so a project beside another loop builds only what carries its label; a project without a watch section has no label to build by, so nothing is listed for it.
    private function rulesOf(string $project): IntakeRules
    {
        return new IntakeRules($this->labels->of($project));
    }

    // An unchanged listing is answered from the stored rows, and so is the history of an item whose update time stands; the rest is taken from the listing, because the milestone due date changes without moving it.
    /**
     * @return list<StoredIssue>
     */
    private function rowsOf(string $project, IssueListing $listing): array
    {
        $stored = [];
        foreach ($this->store->issuesOf($project) as $row) {
            $stored[$row->number()] = $row;
        }

        if ($listing->isUnchanged()) {
            return array_values($stored);
        }

        return array_map(fn (ListedIssue $listed): StoredIssue => $this->rowOf($project, $listed, $stored[$listed->number] ?? null), $listing->issues());
    }

    private function rowOf(string $project, ListedIssue $listed, ?StoredIssue $stored): StoredIssue
    {
        $row = StoredIssue::of($project, new ItemReading($listed, $this->references->waitsFor($listed->body), $this->references->unreadableWaits($listed->body), $this->references->closedBy($listed->body)));
        if ($stored instanceof StoredIssue) {
            $row->keepVouchingOf($stored);
        }

        return $row;
    }

    // Over every row of every listing, because a change of the configured eligibility label makes a row a candidate without moving its update time.
    /**
     * @param list<StoredIssue> $rows
     *
     * @return list<int>
     */
    private function vouchWhatSteers(string $project, array $rows, IntakeRules $rules): array
    {
        $needing = array_map(static fn (QueuedIssue $item): int => $item->number(), $rules->itemsNeedingHistory(array_map(static fn (StoredIssue $row): QueuedIssue => $row->queued(), $rows)));
        $steers = fn (string $account): bool => $this->accounts->steers($project, $account);

        $vouched = [];
        foreach ($rows as $row) {
            if ($row->isVouched() || !in_array($row->number(), $needing, true)) {
                continue;
            }

            $row->vouch(Vouching::of($row->author(), $this->historyOf($project, $row), $steers));
            $vouched[] = $row->number();
        }

        return $vouched;
    }

    // Compared with the row, because an edit in the second of the listing keeps the update time and its history would vouch for text and labels the listing does not hold.
    private function historyOf(string $project, StoredIssue $row): IssueHistory
    {
        $history = $this->histories->of($project, $row->number(), $row->updatedAt());
        if (!$row->isListedAs($history, $this->references)) {
            throw new ForgeReadFailed(sprintf('GitHub holds #%d with other text or labels than it was listed with, so its history is not of the listed item.', $row->number()));
        }

        return $history;
    }

    private function shortListing(string $project, ForgeReadFailed|ForgeRateLimited|TrustNotChecked|ProjectNamesNoEligibilityLabel|ProjectHeldByAnotherWriter|ListingReplacedMeanwhile $short): null
    {
        $this->logger->warning('The open-issue listing of project {project} came back short, so nothing is picked and no wait closes this tick: {reason}', ['project' => $project, 'reason' => $short->getMessage(), 'exception' => $short]);

        return null;
    }

    /**
     * @param list<StoredIssue> $rows
     */
    private function planOver(string $project, array $rows, IntakeRules $rules): IntakePlan
    {
        return $this->planOverWith($rules, $rows, $this->store->failuresOf($project));
    }

    // Another worker that stored a listing meanwhile holds the newer rows, but vouched them under its own eligibility label, so a row that is a candidate under this one has its history read first; the rows are not written again, because only a write that won may write.
    private function planOverTheNewerRows(string $project, IntakeRules $rules): ?IntakePlan
    {
        $readBefore = $this->store->syncStateOf($project);
        $newer = $this->store->issuesOf($project);
        try {
            $this->vouchWhatSteers($project, $newer, $rules);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $short) {
            return $this->shortListing($project, $short);
        }

        $failures = $this->store->failuresOf($project);
        $this->stillNewestSince($project, $readBefore);

        return $this->planOverWith($rules, $newer, $failures);
    }

    /**
     * @param list<StoredIssue>  $rows
     * @param array<int, string> $failures
     */
    private function planOverWith(IntakeRules $rules, array $rows, array $failures): IntakePlan
    {
        return $rules->planOf(array_map(static fn (StoredIssue $row): QueuedIssue => $row->queued(), $rows), $failures);
    }

    // Checked once, after the last read the plan is answered from, and never retried, because a winning write is never answered from rows older than the newest committed listing, and the history and failure reads leave time for a third worker to commit one.
    private function stillNewestSince(string $project, SyncState $readBefore): void
    {
        if ($this->store->syncStateOf($project)->generation !== $readBefore->generation) {
            throw ListingReplacedMeanwhile::of($project);
        }
    }
}
