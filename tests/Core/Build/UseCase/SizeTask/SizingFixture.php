<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\EligibleTasks;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
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

    public function __construct(
        private ManagerRegistry $registry,
        public InMemoryLedger $ledger = new InMemoryLedger(),
        int $number = 7,
    ) {
        $this->parked = ParkedTaskTable::inMemory();
        $this->comments = new PostedTaskComments();
        $this->issue = new SampleIssue($number);
    }

    public function sizing(SizingVendor $vendor, SteeringAccounts $accounts = new SteeringLogins(SampleIssue::OWNER)): SizeTask
    {
        return new SizeTask(
            $this->eligibleTasks($accounts),
            $vendor,
            new SizingRecords($this->ledger),
            new ParkedTasks($this->parked, $this->ledger, $this->registry, new MockClock('2026-11-28 12:00:00 UTC')),
            $this->comments,
            new LockFactory(new InMemoryStore()),
        );
    }

    public function eligibleTasks(SteeringAccounts $accounts = new SteeringLogins(SampleIssue::OWNER)): EligibleTasks
    {
        return new EligibleTasks($this->issue, $this->issue, $accounts, new EligibilityLabels(new ProjectEligibilityLabel('alpha', SampleIssue::LABEL), new ProjectEligibilityLabel('sample', SampleIssue::LABEL)));
    }
}
