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

    private const string HOME_FOLDER = '~/(home|Users|root)/~';

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

    // The keys the Trends charts read were added to a recording made before them, derived from what it already held (#3053), so taking them out and deriving them again gives the shipped text back.
    public function testTheShippedTrendsKeysAreDerivedFromTheRecordedContent(): void
    {
        $format = new RecordingFormat(StoredSerializerFactory::create());
        $shipped = $this->shipped();
        $before = new Recording($shipped->reviewedAt, $shipped->intaktVersion, $shipped->models, $shipped->project, $shipped->change, array_values(array_map(
            $this->withoutFallback(...),
            array_filter($shipped->events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::ReviewerFindingsRecorded !== $event->kind),
        )), $shipped->updates);

        self::assertCount(2, array_filter($shipped->events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::ReviewerFindingsRecorded === $event->kind));
        self::assertSame($this->text(), $format->write(new RecordingWithTrendsKeys(StoredSerializerFactory::create())->of($before)));
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
    public function testTheShippedRecordingHoldsNoLocalPathHostOrTokenInItsTextOrAnyDecodedPayload(string $pattern): void
    {
        self::assertSame([], $this->textsMatching($pattern, $this->text()));
    }

    // The file stores each payload as a JSON string with escaped slashes, so a path quoted in one only shows once decoded (#3232).
    public function testAHomeFolderQuotedInsideOneLedgerPayloadIsFoundByThePrivateTextCheck(): void
    {
        $format = new RecordingFormat(StoredSerializerFactory::create());
        $text = $format->write($this->withRepository('/home/someone/work', $this->shipped()));

        self::assertDoesNotMatchRegularExpression(self::HOME_FOLDER, $text);
        self::assertNotSame([], $this->textsMatching(self::HOME_FOLDER, $text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateText(): iterable
    {
        yield 'a home folder' => [self::HOME_FOLDER];
        yield 'a machine folder' => ['~/(srv|opt|mnt)/~'];
        yield 'the system temp folder' => ['~(?<![\w./-])/tmp\b~'];
        yield 'a provider or forge token' => ['~\b(sk-|ghp_|gho_|github_pat_|xox[bp]-)~'];
        yield 'a private key' => ['~PRIVATE KEY~'];
        yield 'a bearer header' => ['~Bearer\s+\S~i'];
        yield 'an email address' => ['~[\w.+-]+@[\w-]+(\.[\w-]+)+~'];
    }

    /**
     * @return list<string>
     */
    private function textsMatching(string $pattern, string $text): array
    {
        return array_values(array_filter($this->scannedTexts($text), static fn (string $scanned): bool => 1 === preg_match($pattern, $scanned)));
    }

    /**
     * @return list<string>
     */
    private function scannedTexts(string $text): array
    {
        $recording = new RecordingFormat(StoredSerializerFactory::create())->read($text);

        return [
            $text,
            $this->unescaped($text),
            ...array_map(fn (RecordedLedgerEvent $event): string => $this->unescaped($event->payload), $recording->events),
            ...array_map(static fn (CapturedUpdate $update): string => $update->data, $recording->updates),
        ];
    }

    private function unescaped(string $json): string
    {
        return json_encode(json_decode($json, flags: JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function withRepository(string $repository, Recording $shipped): Recording
    {
        $first = $shipped->events[0];
        /** @var array<string, mixed> $payload */
        $payload = json_decode($first->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload['repository'] = $repository;
        $events = [new RecordedLedgerEvent($first->id, $first->kind, json_encode($payload, JSON_THROW_ON_ERROR), $first->recordedAt), ...array_slice($shipped->events, 1)];

        return new Recording($shipped->reviewedAt, $shipped->intaktVersion, $shipped->models, $shipped->project, $shipped->change, $events, $shipped->updates);
    }

    private function withoutFallback(RecordedLedgerEvent $event): RecordedLedgerEvent
    {
        if (LedgerEventKind::JudgeDone !== $event->kind) {
            return $event;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        unset($payload['fallback']);

        return new RecordedLedgerEvent($event->id, $event->kind, json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $event->recordedAt);
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
