<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\RecordDemo;

use Closure;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use Intakt\Tests\Support\StoredSerializerFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

// Each case is the shipped recording with one change, because the helper exists only for that file and must refuse what it cannot derive.
#[CoversClass(RecordingWithTrendsKeys::class)]
#[UsesClass(RecordingFormat::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(CapturedUpdate::class)]
#[UsesClass(ReviewerFindingsRecorded::class)]
final class RecordingWithTrendsKeysTest extends TestCase
{
    private const string SHIPPED = '/fixtures/demo/sample-change-review.json';

    public function testARecordingWhoseJudgementCarriesNoJudgeNoteIsRefused(): void
    {
        $recording = $this->shippedWith(LedgerEventKind::JudgeDone, static function (array $payload): array {
            /** @var list<array<string, mixed>> $findings */
            $findings = $payload['findings'];
            $payload['findings'] = array_map(static fn (array $finding): array => array_diff_key($finding, ['judge_note' => true]), $findings);

            return $payload;
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No finding of judge_done carries a judge note');

        $this->upgraded($recording);
    }

    public function testARecordingWhereTwoReviewersReportedFindingsIsRefused(): void
    {
        $recording = $this->shippedWith(LedgerEventKind::VerdictReceived, static fn (array $payload): array => ['findings' => 0 === $payload['findings'] ? 1 : 3] + $payload);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('More than one reviewer reported findings');

        $this->upgraded($recording);
    }

    public function testARecordingWithTwoJudgementsIsRefused(): void
    {
        $shipped = $this->shipped();
        $events = [];
        foreach ($shipped->events as $event) {
            $events[] = $event;
            if (LedgerEventKind::JudgeDone === $event->kind) {
                $events[] = new RecordedLedgerEvent(substr($event->id, 0, -1).'f', $event->kind, $event->payload, $event->recordedAt);
            }
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exactly one judge_done row');

        $this->upgraded($this->withEvents($shipped, $events));
    }

    public function testARecordingWithAVerdictFromAnotherRoundThanTheJudgementIsRefused(): void
    {
        $recording = $this->shippedWith(LedgerEventKind::VerdictReceived, static fn (array $payload): array => 0 === $payload['findings'] ? ['round' => '01a1f261-3829-7222-812d-3260f5198465'] + $payload : $payload);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('from another round than its judge_done row');

        $this->upgraded($recording);
    }

    private function upgraded(Recording $recording): Recording
    {
        return new RecordingWithTrendsKeys(StoredSerializerFactory::create())->of($recording);
    }

    /**
     * @param Closure(array<string, mixed>): array<string, mixed> $change
     */
    private function shippedWith(LedgerEventKind $kind, Closure $change): Recording
    {
        $shipped = $this->shipped();

        return $this->withEvents($shipped, array_map(static function (RecordedLedgerEvent $event) use ($kind, $change): RecordedLedgerEvent {
            if ($kind !== $event->kind) {
                return $event;
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);

            return new RecordedLedgerEvent($event->id, $event->kind, json_encode($change($payload), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $event->recordedAt);
        }, $shipped->events));
    }

    /**
     * @param list<RecordedLedgerEvent> $events
     */
    private function withEvents(Recording $recording, array $events): Recording
    {
        return new Recording($recording->reviewedAt, $recording->intaktVersion, $recording->models, $recording->project, $recording->change, $events, $recording->updates);
    }

    private function shipped(): Recording
    {
        $text = file_get_contents(dirname(__DIR__, 5).self::SHIPPED);
        self::assertIsString($text);

        return new RecordingFormat(StoredSerializerFactory::create())->read($text);
    }
}
