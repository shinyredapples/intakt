<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\WorkerThreads;
use Intakt\Core\Server\Infrastructure\AdminApi;
use Intakt\Core\Server\Infrastructure\AdminApiServerStatus;
use Intakt\Core\Server\Infrastructure\PrometheusSamples;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminApiServerStatus::class)]
#[CoversClass(PrometheusSamples::class)]
#[UsesClass(AdminApi::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
final class AdminApiServerStatusTest extends TestCase
{
    public function testRecordedThreadsCountOnlyWorkerThreadsAsBusyOrIdle(): void
    {
        $reading = new RecordedAdminApi()->status()->read();

        self::assertEquals(new WorkerThreads(
            busy: 0,
            idle: 2,
        ), $reading->threads);
    }

    public function testABusyWorkerThreadCountsAsBusy(): void
    {
        $api = new RecordedAdminApi()->answering('/frankenphp/threads', $this->threadsWithFirstWorker(['IsBusy' => true, 'IsWaiting' => false]));

        self::assertEquals(new WorkerThreads(
            busy: 1,
            idle: 1,
        ), $api->status()->read()->threads);
    }

    public function testARestartingWorkerThreadIsNeitherBusyNorIdle(): void
    {
        $api = new RecordedAdminApi()->answering('/frankenphp/threads', $this->threadsWithFirstWorker(['State' => 'restarting']));

        self::assertEquals(new WorkerThreads(
            busy: 0,
            idle: 1,
        ), $api->status()->read()->threads);
    }

    public function testRecordedMetricsGiveQueueDepthAndWorkerRestarts(): void
    {
        $reading = new RecordedAdminApi()->status()->read();

        self::assertEquals(new ServerMetrics(
            queueDepth: 0,
            workerRestarts: 2,
        ), $reading->metrics);
    }

    public function testWorkerQueuesAddToTheQueueDepth(): void
    {
        $metrics = str_replace('frankenphp_queue_depth 0', "frankenphp_queue_depth 1\nfrankenphp_worker_queue_depth{worker=\"app\"} 3", RecordedAdminApi::fixture('metrics.txt'));
        $api = new RecordedAdminApi()->answering('/metrics', $metrics);

        self::assertEquals(new ServerMetrics(
            queueDepth: 4,
            workerRestarts: 2,
        ), $api->status()->read()->metrics);
    }

    public function testAServerThatNeverRestartedAWorkerCountsNoRestarts(): void
    {
        $metrics = preg_replace('/^frankenphp_worker_restarts.*$/m', '', RecordedAdminApi::fixture('metrics.txt'));
        $api = new RecordedAdminApi()->answering('/metrics', (string) $metrics);

        self::assertEquals(new ServerMetrics(
            queueDepth: 0,
            workerRestarts: 0,
        ), $api->status()->read()->metrics);
    }

    public function testRecordedHubReadinessIsHealthy(): void
    {
        self::assertSame(HubHealth::Healthy, new RecordedAdminApi()->status()->read()->hub);
    }

    public function testAHubAnsweringUnavailableIsUnhealthy(): void
    {
        $api = new RecordedAdminApi()->answering('/mercure/health/ready', '{"status":"unavailable"}', 503);

        self::assertSame(HubHealth::Unhealthy, $api->status()->read()->hub);
    }

    public function testAHubReportingAnotherStatusIsUnhealthy(): void
    {
        $api = new RecordedAdminApi()->answering('/mercure/health/ready', '{"status":"degraded"}');

        self::assertSame(HubHealth::Unhealthy, $api->status()->read()->hub);
    }

    public function testAnUnreachableAdminApiIsDownForEverySource(): void
    {
        $reading = new RecordedAdminApi()->unreachable()->status()->read();

        self::assertEquals(new EndpointDown('/frankenphp/threads did not answer'), $reading->threads);
        self::assertInstanceOf(EndpointDown::class, $reading->metrics);
        self::assertInstanceOf(EndpointDown::class, $reading->hub);
    }

    public function testTheReasonOfAnUnreachableAdminApiLeavesOutTheConfiguredUrl(): void
    {
        $reading = new RecordedAdminApi()->unreachable()->status('http://localhost:2019?password=synthetic-query-password')->read();

        self::assertSame(['/frankenphp/threads did not answer', '/metrics did not answer', '/mercure/health/ready did not answer'], array_map(
            static fn (mixed $source): string => $source instanceof EndpointDown ? $source->reason : 'not down',
            [$reading->threads, $reading->metrics, $reading->hub],
        ));
    }

    public function testAHubWithoutAHealthEndpointIsDown(): void
    {
        $api = new RecordedAdminApi()->answering('/mercure/health/ready', '{"error":"unknown health endpoint"}', 404);

        self::assertEquals(new EndpointDown('/mercure/health/ready answered with status 404'), $api->status()->read()->hub);
    }

    public function testThreadsAnsweredWithAnErrorStatusAreDown(): void
    {
        $api = new RecordedAdminApi()->answering('/frankenphp/threads', '404 page not found', 404);

        self::assertEquals(new EndpointDown('/frankenphp/threads answered with status 404'), $api->status()->read()->threads);
    }

    public function testThreadsInAnotherShapeAreDown(): void
    {
        $api = new RecordedAdminApi()->answering('/frankenphp/threads', '{"ThreadDebugStates":[{"Name":"Worker PHP Thread","State":"ready","IsBusy":"no"}]}');

        self::assertEquals(new EndpointDown('/frankenphp/threads answered in an unusable shape'), $api->status()->read()->threads);
    }

    public function testMetricsWithoutFrankenPhpSamplesAreDown(): void
    {
        $api = new RecordedAdminApi()->answering('/metrics', "go_goroutines 20\n");

        self::assertEquals(new EndpointDown('/metrics answered in an unusable shape'), $api->status()->read()->metrics);
    }

    public function testMetricsWithAnUnreadableSampleAreDown(): void
    {
        $api = new RecordedAdminApi()->answering('/metrics', RecordedAdminApi::fixture('metrics.txt')."frankenphp_worker_restarts{worker=\"app\"} NaN-ish\n");

        self::assertEquals(new EndpointDown('/metrics answered in an unusable shape'), $api->status()->read()->metrics);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unrelatedSpecialValueSamples(): iterable
    {
        yield 'NaN summary quantile' => ["go_gc_duration_seconds{quantile=\"0.5\"} NaN\n"];
        yield '+Inf gauge' => ["some_gauge +Inf\n"];
        yield '-Inf gauge' => ["some_gauge -Inf\n"];
    }

    #[DataProvider('unrelatedSpecialValueSamples')]
    public function testASpecialValueInAnUnrelatedSeriesLeavesTheMetricsRead(string $sample): void
    {
        $api = new RecordedAdminApi()->answering('/metrics', RecordedAdminApi::fixture('metrics.txt').$sample);

        self::assertEquals(new ServerMetrics(
            queueDepth: 0,
            workerRestarts: 2,
        ), $api->status()->read()->metrics);
    }

    public function testASpecialValueInACountedSeriesIsDown(): void
    {
        $api = new RecordedAdminApi()->answering('/metrics', RecordedAdminApi::fixture('metrics.txt')."frankenphp_worker_queue_depth{worker=\"app\"} NaN\n");

        self::assertEquals(new EndpointDown('/metrics answered in an unusable shape'), $api->status()->read()->metrics);
    }

    public function testAHubAnswerInAnotherShapeIsDown(): void
    {
        $api = new RecordedAdminApi()->answering('/mercure/health/ready', 'ok');

        self::assertEquals(new EndpointDown('/mercure/health/ready answered in an unusable shape'), $api->status()->read()->hub);
    }

    public function testOneSourceDownLeavesTheOthersRead(): void
    {
        $reading = new RecordedAdminApi()->answering('/metrics', '', 500)->status()->read();

        self::assertEquals(new WorkerThreads(
            busy: 0,
            idle: 2,
        ), $reading->threads);
        self::assertInstanceOf(EndpointDown::class, $reading->metrics);
        self::assertSame(HubHealth::Healthy, $reading->hub);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function threadsWithFirstWorker(array $changes): string
    {
        /** @var array{ThreadDebugStates: list<array<string, mixed>>} $threads */
        $threads = json_decode(RecordedAdminApi::fixture('threads.json'), true, flags: JSON_THROW_ON_ERROR);
        $threads['ThreadDebugStates'][4] = [...$threads['ThreadDebugStates'][4], ...$changes];

        return json_encode($threads, JSON_THROW_ON_ERROR);
    }
}
