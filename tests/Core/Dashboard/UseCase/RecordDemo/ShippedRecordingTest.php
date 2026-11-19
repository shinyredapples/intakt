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

/**
 * The image points INTAKT_DEMO_RECORDING at this file, so /demo works without a key only while it still reads.
 */
#[CoversClass(RecordingFormat::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(CapturedUpdate::class)]
final class ShippedRecordingTest extends TestCase
{
    private const string SHIPPED = '/fixtures/demo/sample-change-review.json';

    public function testTheShippedRecordingReadsWithTheCurrentRecordingFormat(): void
    {
        $recording = self::shipped();

        self::assertSame('sample-change', $recording->change);
        self::assertNotEmpty($recording->updates);
    }

    public function testTheShippedRecordingHoldsBothVerdictsAndTheJudgesActList(): void
    {
        $kinds = array_map(static fn (RecordedLedgerEvent $event): LedgerEventKind => $event->kind, self::shipped()->events);

        self::assertCount(2, array_keys($kinds, LedgerEventKind::VerdictReceived, true));
        self::assertContains(LedgerEventKind::JudgeDone, $kinds);
    }

    public function testTheImagePointsTheDemoAtTheShippedRecording(): void
    {
        self::assertMatchesRegularExpression(
            '~^\s+INTAKT_DEMO_RECORDING=/app'.preg_quote(self::SHIPPED, '~').'$~m',
            self::file('/Dockerfile'),
        );
    }

    public function testComposeLeavesTheRecordingToEnvLocalSoAnOwnerCanOverrideIt(): void
    {
        self::assertStringNotContainsString('INTAKT_DEMO_RECORDING', self::file('/compose.yaml'));
    }

    #[DataProvider('privateText')]
    public function testTheShippedRecordingHoldsNoLocalPathHostOrToken(string $pattern): void
    {
        self::assertDoesNotMatchRegularExpression($pattern, self::text());
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

    private static function shipped(): Recording
    {
        return new RecordingFormat(StoredSerializerFactory::create())->read(self::text());
    }

    private static function text(): string
    {
        return self::file(self::SHIPPED);
    }

    private static function file(string $path): string
    {
        $text = file_get_contents(dirname(__DIR__, 5).$path);
        self::assertIsString($text);

        return $text;
    }
}
