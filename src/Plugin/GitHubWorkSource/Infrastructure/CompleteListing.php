<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

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
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
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
        private IntakeRules $rules,
        private IssueReferences $references,
        private LoggerInterface $logger,
    ) {
    }

    // Null when the listing came back short, so nothing is picked and no wait closes this tick.
    #[NoDiscard]
    public function planOf(string $project): ?IntakePlan
    {
        $askedWith = $this->store->validatorOf($project);
        try {
            $listing = $this->reads->listOpen($project, $askedWith);
            $rows = $this->rowsOf($project, $listing);
            $vouched = $this->vouchWhatSteers($project, $rows);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $short) {
            $this->logger->warning('The open-issue listing of project {project} came back short, so nothing is picked and no wait closes this tick: {reason}', ['project' => $project, 'reason' => $short->getMessage(), 'exception' => $short]);

            return null;
        }

        if ($listing->isUnchanged() && [] === $vouched) {
            return $this->planOver($project, $rows);
        }

        $listed = new ListingRows($rows, $listing->isUnchanged() ? $askedWith : $listing->validator);

        // Another worker that stored a listing meanwhile holds the newer rows, so those are the ones to answer from.
        return $this->planOver($project, $this->store->recordIfStill($project, $askedWith, $listed) ? $rows : $this->store->issuesOf($project));
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
    private function vouchWhatSteers(string $project, array $rows): array
    {
        $needing = array_map(static fn (QueuedIssue $item): int => $item->number(), $this->rules->itemsNeedingHistory(array_map(static fn (StoredIssue $row): QueuedIssue => $row->queued(), $rows)));
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
        $body = $history->body;
        if (!$row->isListedWith($history->currentLabels, $this->references->waitsFor($body), $this->references->unreadableWaits($body), $this->references->closedBy($body))) {
            throw new ForgeReadFailed(sprintf('GitHub holds #%d with other text or labels than it was listed with, so its history is not of the listed item.', $row->number()));
        }

        return $history;
    }

    /**
     * @param list<StoredIssue> $rows
     */
    private function planOver(string $project, array $rows): IntakePlan
    {
        return $this->rules->planOf(array_map(static fn (StoredIssue $row): QueuedIssue => $row->queued(), $rows), $this->store->failuresOf($project));
    }
}
