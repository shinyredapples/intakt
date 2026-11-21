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
use Symfony\Component\DomCrawler\Crawler;

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

    // The replay publishes the recorded update to a review page that is already open, so a card the page renders differently would change once the juror reloads.
    public function testTheShippedJudgedUpdateShowsTheDroppedFindingWithTheQuotesTheCheckCounted(): void
    {
        $judged = array_values(array_filter(array_map(
            static fn (CapturedUpdate $update): ?string => 1 === preg_match('~<turbo-stream action="replace" target="round-[^"]+-judged"><template>(.*?)</template></turbo-stream>~s', $update->data, $part) ? $part[1] : null,
            $this->shipped()->updates,
        )));
        self::assertCount(1, $judged);
        $card = new Crawler($judged[0])->filter('[data-dropped="F3"]');

        self::assertSame('Quote not found in the code', $card->filter('.fc-call')->text());
        self::assertNull($card->filter('details.fc-quotes')->attr('open'));
        self::assertSame(['ann', 'joanne'], $card->filter('details.fc-quotes .fc-quote-list code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertSame('Dropped before the judge: none of its 2 quoted code fragments is in the change', $card->filter('details.fc-quotes .fc-reason')->text());
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
