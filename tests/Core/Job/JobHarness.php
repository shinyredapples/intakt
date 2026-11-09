<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\DockerEngineApi;
use Intakt\Core\Job\Infrastructure\DockerJobRunner;
use Intakt\Core\Job\Infrastructure\EgressProxy;
use Intakt\Core\Job\Infrastructure\JobContainerBody;
use Intakt\Core\Job\Infrastructure\JobLedger;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\StartCheckReader;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// Wires the runner over a fake engine and an in-memory lock store, the two doors a job leaves the process through.
final readonly class JobHarness
{
    public FakeDockerEngine $engine;
    public InMemoryLedger $ledger;
    public JobLocks $locks;
    public DockerEngineApi $api;

    public function __construct(int $ledgerAccepts = PHP_INT_MAX)
    {
        $this->engine = new FakeDockerEngine();
        $this->ledger = new InMemoryLedger($ledgerAccepts);
        $this->locks = new JobLocks(new LockFactory(new InMemoryStore()));
        $this->api = new DockerEngineApi('/unused.sock', new MockHttpClient($this->engine));
    }

    public function runner(): DockerJobRunner
    {
        $proxy = new EgressProxy('job-proxy:1');

        return new DockerJobRunner(
            $this->api,
            new JobContainerBody($proxy),
            $proxy,
            new StartCheckReader(),
            new JobLedger($this->ledger),
            $this->locks,
            new NullLogger(),
        );
    }
}
