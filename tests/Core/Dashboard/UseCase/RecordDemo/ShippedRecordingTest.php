<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\RecordDemo;

use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

// The image points INTAKT_DEMO_RECORDING at this file, so /demo works without a key only while it still reads.
#[CoversClass(RecordingFormat::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(CapturedUpdate::class)]
final class ShippedRecordingTest extends TestCase
{
    private const string SHIPPED = '/fixtures/demo/sample-change-review.json';

    public function testTheShippedRecordingReadsWithTheCurrentRecordingFormat(): void
    {
        $recording = $this->shipped();

        self::assertSame('sample-change', $recording->change);
        self::assertNotEmpty($recording->updates);
    }

    public function testTheShippedRecordingHoldsBothVerdictsAndTheJudgesActList(): void
    {
        $kinds = array_map(static fn (RecordedLedgerEvent $event): LedgerEventKind => $event->kind, $this->shipped()->events);

        self::assertCount(2, array_keys($kinds, LedgerEventKind::VerdictReceived, true));
        self::assertContains(LedgerEventKind::JudgeDone, $kinds);
    }

    // The Trends charts read who reported what and whether the judge answered, so the keyless demo fills them only while the recording holds both (#3053, #3221).
    public function testTheShippedRecordingHoldsWhatEachReviewerReportedAndTheJudgesOwnAnswer(): void
    {
        $events = $this->shipped()->events;
        $reported = array_filter($events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::ReviewerFindingsRecorded === $event->kind);
        $judged = array_values(array_filter($events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::JudgeDone === $event->kind));

        self::assertCount(2, $reported);
        $reviewers = array_map(static fn (RecordedLedgerEvent $event): mixed => json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR)['reviewer'] ?? null, $reported);
        self::assertCount(2, array_unique($reviewers));
        self::assertNotContains(null, $reviewers);
        self::assertCount(1, $judged);
        $answer = json_decode($judged[0]->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertArrayHasKey('fallback', $answer);
        self::assertFalse($answer['fallback']);
    }

    public function testTheImagePointsTheDemoAtTheShippedRecording(): void
    {
        self::assertMatchesRegularExpression(
            '~^\s+INTAKT_DEMO_RECORDING=/app'.preg_quote(self::SHIPPED, '~').'$~m',
            $this->file('/Dockerfile'),
        );
    }

    public function testComposeLeavesTheRecordingToEnvLocalSoAnOwnerCanOverrideIt(): void
    {
        // A compose `environment:` entry would win over `env_file: .env.local`, so the owner could not override it.
        self::assertStringNotContainsString('INTAKT_DEMO_RECORDING', $this->file('/compose.yaml'));
    }

    #[DataProvider('privateText')]
    public function testTheShippedRecordingHoldsNoLocalPathHostOrToken(string $pattern): void
    {
        self::assertDoesNotMatchRegularExpression($pattern, $this->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateText(): iterable
    {
        yield 'a home folder' => ['~/(home|Users|root)/~'];
        yield 'a machine folder' => ['~/(srv|opt|mnt)/~'];
        yield 'a provider or forge token' => ['~\b(sk-|ghp_|gho_|github_pat_|xox[bp]-)~'];
        yield 'a private key' => ['~PRIVATE KEY~'];
        yield 'a bearer header' => ['~Bearer\s+\S~i'];
    }

    private function shipped(): Recording
    {
        return new RecordingFormat(StoredSerializerFactory::create())->read($this->text());
    }

    private function text(): string
    {
        return $this->file(self::SHIPPED);
    }

    private function file(string $path): string
    {
        $text = file_get_contents(dirname(__DIR__, 5).$path);
        self::assertIsString($text);

        return $text;
    }
}
