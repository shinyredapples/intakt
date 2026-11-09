<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\DockerEngineApi;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\LabeledResource;
use Intakt\Core\Job\UseCase\SweepOrphanedJobs\SweepOrphanedJobs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SweepOrphanedJobs::class)]
#[UsesClass(DockerEngineApi::class)]
#[UsesClass(JobLocks::class)]
#[UsesClass(LabeledResource::class)]
final class SweepOrphanedJobsTest extends TestCase
{
    public function testContainersLabeledForAJobNoLongerRunningAreRemovedAtOrchestratorStart(): void
    {
        $harness = new JobHarness();
        $harness->engine->seedContainer('intakt-job-old', 'old');
        $harness->engine->seedContainer('intakt-job-old-proxy', 'old');
        $harness->engine->seedNetwork('intakt-job-old', 'old');

        $swept = $this->sweep($harness);

        self::assertSame(['old'], $swept);
        self::assertSame([], $harness->engine->containers);
        self::assertSame([], $harness->engine->networks);
    }

    public function testAJobAnotherLiveWorkerOwnsIsLeftAlone(): void
    {
        $harness = new JobHarness();
        $harness->engine->seedContainer('intakt-job-live', 'live');
        $harness->engine->seedContainer('intakt-job-old', 'old');
        $owner = $harness->locks->claim('live');

        $swept = $this->sweep($harness);

        self::assertNotNull($owner);
        self::assertSame(['old'], $swept);
        self::assertSame(['intakt-job-live'], $harness->engine->namesOfContainers());
    }

    public function testAContainerWithoutTheJobLabelIsNeverRemoved(): void
    {
        $harness = new JobHarness();
        $harness->engine->containers['c9'] = ['name' => 'someone-elses', 'body' => ['Labels' => []]];

        self::assertSame([], $this->sweep($harness));
        self::assertSame(['someone-elses'], $harness->engine->namesOfContainers());
    }

    public function testASweepOfNothingRemovesNothing(): void
    {
        self::assertSame([], $this->sweep(new JobHarness()));
    }

    public function testAJobIsSweptAgainAfterItsLockIsFree(): void
    {
        $harness = new JobHarness();
        $harness->engine->seedContainer('intakt-job-old', 'old');
        $lock = $harness->locks->claim('old');
        self::assertNotNull($lock);
        self::assertSame([], $this->sweep($harness));

        $lock->release();

        self::assertSame(['old'], $this->sweep($harness));
    }

    /**
     * @return list<string>
     */
    private function sweep(JobHarness $harness): array
    {
        return new SweepOrphanedJobs($harness->api, $harness->locks, new NullLogger())->sweep();
    }
}
