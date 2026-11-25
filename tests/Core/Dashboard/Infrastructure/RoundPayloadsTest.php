<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\PayloadDefaults;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use Intakt\Core\Review\Infrastructure\StoredFindingIdsNormalizer;
use Intakt\Core\Review\Infrastructure\StoredJsonEncoder;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RoundPayloads::class)]
#[UsesClass(StoredSerializerFactory::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(PayloadDefaults::class)]
#[UsesClass(ReviewerFindingsRecorded::class)]
#[UsesClass(FindingIds::class)]
#[UsesClass(StoredFindingIdsNormalizer::class)]
#[UsesClass(StoredJsonEncoder::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(RecordedEvent::class)]
final class RoundPayloadsTest extends TestCase
{
    public function testAnUnknownSeverityKeepsTheReportersFindingIdsAndLeavesTheSeveritiesOut(): void
    {
        $reader = new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create()));
        $row = new RecordedEvent(Uuid::v7(), new LedgerEvent(
            LedgerEventKind::ReviewerFindingsRecorded,
            '{"round":"r1","reviewer":"r2","findings":["F1"],"severities":{"F1":"P9"}}',
        ), new DateTimeImmutable('2026-11-01T12:00:00Z'));

        $report = $reader->reviewerFindingsOf($row);

        self::assertNotNull($report);
        self::assertSame(['F1'], [...$report->findings]);
        self::assertNull($report->severities);
    }
}
