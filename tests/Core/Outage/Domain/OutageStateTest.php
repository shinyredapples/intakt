<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Domain;

use DateTimeImmutable;
use Intakt\Core\Outage\Contract\Reachability;
use Intakt\Core\Outage\Domain\OutageState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutageState::class)]
final class OutageStateTest extends TestCase
{
    public function testAnOfflineStateKeepsTheTimeItBeganInUtc(): void
    {
        $state = new OutageState(Reachability::Offline, new DateTimeImmutable('2026-11-20 10:00:00+02:00'));

        self::assertSame(Reachability::Offline, $state->reachability);
        self::assertSame('2026-11-20T08:00:00+00:00', $state->since->format(DATE_ATOM));
    }
}
