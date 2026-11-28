<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\EligibleTasks;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Build\UseCase\SizeTask\SplitTask;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TaskComments;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Intakt\Tests\Core\Build\ParkedTaskTable;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// Everything a sizing touches around the vendor, in memory, so each test names only the verdict and the one fact it changes.
final readonly class SizingFixture
{
    public Connection $parked;

    public PostedTaskComments $comments;

    public SampleIssue $issue;

    public FiledParts $parts;

    public KindRefusingLedger $splitLedger;

    // Every record goes to the kept ledger, the in-memory one unless a test passes the persistent ledger.
    public Ledger $kept;

    public function __construct(
        private ManagerRegistry $registry,
        public InMemoryLedger $ledger = new InMemoryLedger(),
        int $number = 7,
        ?Ledger $kept = null,
    ) {
        $this->kept = $kept ?? $this->ledger;
        $this->parked = ParkedTaskTable::inMemory();
        $this->comments = new PostedTaskComments();
        $this->parts = new FiledParts();
        $this->splitLedger = new KindRefusingLedger($this->kept);
        $this->issue = new SampleIssue($number);
    }

    public function sizing(SizingVendor $vendor, SteeringAccounts $accounts = new SteeringLogins(SampleIssue::OWNER), ?TaskComments $comments = null): SizeTask
    {
        return new SizeTask(
            $this->eligibleTasks($accounts),
            $vendor,
            new SizingRecords($this->kept),
            $this->parkedTasks(),
            $comments ?? $this->comments,
            new LockFactory(new InMemoryStore()),
            new SplitTask($this->splitLedger, $this->parts, $comments ?? $this->comments),
        );
    }

    public function parkedTasks(): ParkedTasks
    {
        return new ParkedTasks($this->parked, $this->kept, $this->registry, new MockClock('2026-11-28 12:00:00 UTC'));
    }

    public function eligibleTasks(SteeringAccounts $accounts = new SteeringLogins(SampleIssue::OWNER)): EligibleTasks
    {
        $labels = array_map(static fn (string $project): ProjectEligibilityLabel => new ProjectEligibilityLabel($project, SampleIssue::LABEL), ['alpha', 'sample', 'widget']);

        return new EligibleTasks($this->issue, $this->issue, $accounts, new EligibilityLabels(...$labels));
    }
}
