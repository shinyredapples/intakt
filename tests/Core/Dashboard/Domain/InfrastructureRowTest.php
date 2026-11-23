<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\FigureState;
use Intakt\Core\Dashboard\Domain\InfrastructureFigure;
use Intakt\Core\Dashboard\Domain\InfrastructureRow;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\WorkerThreads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfrastructureRow::class)]
#[CoversClass(InfrastructureFigure::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
final class InfrastructureRowTest extends TestCase
{
    public function testAReadingWithEverySourceUpShowsThreadsBusyAndIdleQueueDepthRestartsAndHubHealth(): void
    {
        $row = InfrastructureRow::of(new ServerReading(new WorkerThreads(3, 5), new ServerMetrics(1, 2), HubHealth::Healthy), $this->unchanged(...));

        self::assertSame([
            ['Threads busy', '3', 'fine', null],
            ['Threads idle', '5', 'fine', null],
            ['Queue depth', '1', 'fine', null],
            ['Worker restarts', '2', 'fine', null],
            ['Hub', 'healthy', 'fine', null],
        ], $this->shown($row));
    }

    public function testASourceThatIsDownShowsItsDownStateWhileTheOtherSourcesStillShowTheirValues(): void
    {
        $row = InfrastructureRow::of(new ServerReading(new EndpointDown('/frankenphp/threads did not answer'), new ServerMetrics(0, 4), HubHealth::Healthy), $this->unchanged(...));

        self::assertSame([
            ['Worker threads', 'down', 'down', '/frankenphp/threads did not answer'],
            ['Queue depth', '0', 'fine', null],
            ['Worker restarts', '4', 'fine', null],
            ['Hub', 'healthy', 'fine', null],
        ], $this->shown($row));
    }

    public function testDownMetricsShowOneDownStateForQueueDepthAndRestarts(): void
    {
        $row = InfrastructureRow::of(new ServerReading(new WorkerThreads(0, 2), new EndpointDown('/metrics answered 500'), HubHealth::Healthy), $this->unchanged(...));

        self::assertSame(['Threads busy', 'Threads idle', 'Server metrics', 'Hub'], array_map(static fn (InfrastructureFigure $figure): string => $figure->label, $row->figures));
        self::assertSame([FigureState::Fine, FigureState::Fine, FigureState::Down, FigureState::Fine], array_map(static fn (InfrastructureFigure $figure): FigureState => $figure->state, $row->figures));
    }

    public function testAnUnhealthyHubIsMarkedUnhealthyAndNotDown(): void
    {
        $hub = array_last(InfrastructureRow::of(new ServerReading(new WorkerThreads(0, 2), new ServerMetrics(0, 0), HubHealth::Unhealthy), $this->unchanged(...))->figures);

        self::assertSame(['unhealthy', FigureState::Unhealthy, null], [$hub?->value, $hub?->state, $hub?->downBecause]);
    }

    public function testTheReasonOfASourceThatIsDownPassesThroughTheGivenRedaction(): void
    {
        $row = InfrastructureRow::of(new ServerReading(new EndpointDown('token abc'), new ServerMetrics(0, 0), HubHealth::Healthy), static fn (string $reason): string => str_replace('abc', '[redacted]', $reason));

        self::assertSame('token [redacted]', array_first($row->figures)?->downBecause);
    }

    public function testARefusedAdminApiAddressShowsEverySourceDownWithTheSameReason(): void
    {
        $row = InfrastructureRow::refused('FRANKENPHP_ADMIN_URL is not a URL.');

        self::assertSame([
            ['Worker threads', 'down', 'down', 'FRANKENPHP_ADMIN_URL is not a URL.'],
            ['Server metrics', 'down', 'down', 'FRANKENPHP_ADMIN_URL is not a URL.'],
            ['Hub', 'down', 'down', 'FRANKENPHP_ADMIN_URL is not a URL.'],
        ], $this->shown($row));
    }

    private function unchanged(string $reason): string
    {
        return $reason;
    }

    /**
     * @return list<array{string, string, string, ?string}>
     */
    private function shown(InfrastructureRow $row): array
    {
        return array_map(static fn (InfrastructureFigure $figure): array => [$figure->label, $figure->value, $figure->state->value, $figure->downBecause], $row->figures);
    }
}
