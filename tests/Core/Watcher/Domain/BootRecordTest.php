<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Domain;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\BootRecord;
use Intakt\Plugin\Forge\Contract\CommitId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BootRecord::class)]
#[UsesClass(BootKey::class)]
#[UsesClass(BootOutcome::class)]
#[UsesClass(CommitId::class)]
final class BootRecordTest extends TestCase
{
    public function testACrashedRecordAnswersUntilItsBackOffEnds(): void
    {
        $record = $this->record(BootOutcome::crashed('composer could not run'));

        self::assertTrue($record->answersAt(new DateTimeImmutable('2026-11-13T10:04:59+00:00')));
        self::assertFalse($record->answersAt(new DateTimeImmutable('2026-11-13T10:05:00+00:00')));
    }

    public function testABootedRecordAnswersAtAnyLaterMoment(): void
    {
        self::assertTrue($this->record(BootOutcome::booted())->answersAt(new DateTimeImmutable('2027-11-13T10:00:00+00:00')));
    }

    public function testAFailedRecordAnswersAtAnyLaterMoment(): void
    {
        self::assertTrue($this->record(BootOutcome::failed('No status.'))->answersAt(new DateTimeImmutable('2027-11-13T10:00:00+00:00')));
    }

    private function record(BootOutcome $outcome): BootRecord
    {
        return new BootRecord(new BootKey('alpha', new CommitId(str_repeat('a', 40)), 'tools-1'), $outcome, new DateTimeImmutable('2026-11-13T10:00:00+00:00'));
    }
}
