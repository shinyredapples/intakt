<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\RecordDemo;

use Intakt\Core\Dashboard\Domain\AiLab;
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
#[UsesClass(AiLab::class)]
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

    // The Trends charts read who reported what and whether the judge answered, so the keyless demo fills them only while the recording holds both (#3053, #3221).
    public function testTheShippedRecordingHoldsWhatEachReviewerReportedAndTheJudgesOwnAnswer(): void
    {
        $events = $this->shipped()->events;
        $reported = array_filter($events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::ReviewerFindingsRecorded === $event->kind);
        $judged = array_values(array_filter($events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::JudgeDone === $event->kind));

        self::assertCount(2, $reported);
        $reviewers = array_map(static function (RecordedLedgerEvent $event): string {
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertIsString($payload['reviewer'] ?? null);

            return $payload['reviewer'];
        }, $reported);
        self::assertCount(2, array_unique($reviewers));
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
    public function testTheShippedRecordingHoldsNoLocalPathHostOrTokenInItsTextOrAnyDecodedPayload(string $pattern): void
    {
        // Only the places are compared, so a failure names where the text is without printing it into the CI log.
        self::assertSame([], $this->placesMatching($pattern, $this->text()));
    }

    // The file stores each payload as a JSON string with escaped slashes, so a path quoted in one only shows once decoded (#3232).
    public function testAHomeFolderQuotedInsideOneLedgerPayloadIsFoundByThePrivateTextCheck(): void
    {
        $text = $this->shippedWith('repository', '/home/someone/work');

        self::assertDoesNotMatchRegularExpression(self::HOME_FOLDER, $text);
        self::assertSame(['the payload of ledger event 0'], $this->placesMatching(self::HOME_FOLDER, $text));
    }

    public function testAPayloadThatHoldsPrivateTextIsReportedByItsPlaceWithoutItsText(): void
    {
        $places = $this->placesMatching(self::HOME_FOLDER, $this->shippedWith('task', 'synthetic-private-task in /home/someone/work'));

        self::assertNotSame([], $places);
        self::assertStringNotContainsString('synthetic-private-task', implode("\n", $places));
        self::assertStringNotContainsString('/home/someone', implode("\n", $places));
    }

    // Encoding the payload again would write the tab as \t, which the pattern's \s does not match.
    public function testABearerHeaderWithATabInsideOneLedgerPayloadIsFound(): void
    {
        $text = $this->shippedWith('task', "Authorization: Bearer\tsynthetic-credential");

        self::assertSame(['the payload of ledger event 0'], $this->placesMatching($this->privateTextPattern('a bearer header'), $text));
    }

    public function testAFolderWhoseNameOnlyStartsWithTmpIsNotTheSystemTempFolder(): void
    {
        $pattern = $this->privateTextPattern('the system temp folder');

        self::assertDoesNotMatchRegularExpression($pattern, '/tmp-backup/work');
        self::assertDoesNotMatchRegularExpression($pattern, '/tmp.d/work');
        self::assertDoesNotMatchRegularExpression($pattern, '/var/tmp/work');
        self::assertDoesNotMatchRegularExpression($pattern, '/tmp+backup/work');
        self::assertDoesNotMatchRegularExpression($pattern, '/tmp~/work');
        self::assertMatchesRegularExpression($pattern, '/tmp/work');
        self::assertMatchesRegularExpression($pattern, 'a workspace in /tmp.');
        self::assertMatchesRegularExpression($pattern, 'a workspace in "/tmp", kept');
        self::assertMatchesRegularExpression($pattern, '/tmp');
    }

    // The file text holds the payload with escaped slashes, so a deeper temp folder reads as a backslash before "/tmp" there.
    public function testADeeperTempFolderInsideOneLedgerPayloadIsNotTheSystemTempFolder(): void
    {
        $text = $this->shippedWith('repository', '/var/tmp/work');

        self::assertSame([], $this->placesMatching($this->privateTextPattern('the system temp folder'), $text));
    }

    public function testTheRootTempFolderInsideOneLedgerPayloadIsFoundInThatPayloadOnly(): void
    {
        $text = $this->shippedWith('repository', '/tmp/work');

        self::assertSame(['the payload of ledger event 0'], $this->placesMatching($this->privateTextPattern('the system temp folder'), $text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateText(): iterable
    {
        yield 'a home folder' => [self::HOME_FOLDER];
        yield 'a machine folder' => ['~/(srv|opt|mnt)/~'];
        // The backslash keeps an escaped deeper path ("\/var\/tmp") out; the decoded strings still show a root temp folder.
        yield 'the system temp folder' => ['~(?<![\w./\\\\-])/tmp(?=$|[/\s"\'),;:]|\.(?!\w))~'];
        yield 'a provider or forge token' => ['~\b(sk-|ghp_|gho_|github_pat_|xox[bp]-)~'];
        yield 'a private key' => ['~PRIVATE KEY~'];
        yield 'a bearer header' => ['~Bearer\s+\S~i'];
        yield 'an email address' => ['~[\w.+-]+@[\w-]+(\.[\w-]+)+~'];
    }

    private function privateTextPattern(string $name): string
    {
        foreach (self::privateText() as $label => [$pattern]) {
            if ($name === $label) {
                return $pattern;
            }
        }

        self::fail(sprintf('No private-text pattern is named "%s".', $name));
    }

    /**
     * @return list<string>
     */
    private function placesMatching(string $pattern, string $text): array
    {
        $matching = [];
        foreach ($this->scannedPlaces($text) as $place => $strings) {
            if ([] !== preg_grep($pattern, $strings)) {
                $matching[] = $place;
            }
        }

        return $matching;
    }

    /**
     * @return array<string, list<string>>
     */
    private function scannedPlaces(string $text): array
    {
        $recording = new RecordingFormat(StoredSerializerFactory::create())->read($text);
        $places = ['the file text' => [$text], 'a string of the decoded file' => $this->strings(json_decode($text, true, flags: JSON_THROW_ON_ERROR))];
        foreach ($recording->events as $index => $event) {
            $places[sprintf('the payload of ledger event %d', $index)] = $this->strings(json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR));
        }

        foreach ($recording->updates as $index => $update) {
            $places[sprintf('the data of live update %d', $index)] = [$update->data];
        }

        return $places;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $decoded): array
    {
        if (is_string($decoded)) {
            return [$decoded];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $strings = [];
        foreach ($decoded as $key => $value) {
            $strings = [...$strings, ...(is_string($key) ? [$key] : []), ...$this->strings($value)];
        }

        return $strings;
    }

    public function testTheShippedRecordingHeadsEachLiveReviewerWithItsAiLabAndKeepsTheIdInTheTitle(): void
    {
        $headings = [];

        foreach ($this->shipped()->updates as $update) {
            preg_match_all('~<h3 title="([^"]+)">[^<(]+ \((.+)\) live</h3>~', $update->data, $found, PREG_SET_ORDER);
            $headings = [...$headings, ...$found];
        }

        self::assertNotEmpty($headings);

        foreach ($headings as [, $id, $name]) {
            self::assertSame(AiLab::modelName($id).' ('.AiLab::of($id)?->value.')', $name);
        }
    }

    public function testTheShippedRecordingNamesEveryReviewerModelCellWithItsAiLabAndKeepsTheIdInTheTitle(): void
    {
        $cells = [];

        foreach ($this->shipped()->updates as $update) {
            preg_match_all('~<td id="[^"]*-model"([^>]*)>([^<]*)</td>~', $update->data, $found, PREG_SET_ORDER);
            $cells = [...$cells, ...$found];
        }

        self::assertNotEmpty($cells);

        foreach ($cells as [, $attributes, $text]) {
            $id = preg_replace('~^ title="([^"]+)"$~', '$1', $attributes);
            self::assertNotSame($attributes, $id, 'model cell without a title');
            self::assertSame(AiLab::modelName((string) $id).' ('.AiLab::of((string) $id)?->value.')', $text);
        }
    }

    private function shippedWith(string $field, string $value): string
    {
        $shipped = $this->shipped();
        $first = $shipped->events[0];
        /** @var array<string, mixed> $payload */
        $payload = json_decode($first->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload[$field] = $value;
        $events = [new RecordedLedgerEvent($first->id, $first->kind, json_encode($payload, JSON_THROW_ON_ERROR), $first->recordedAt), ...array_slice($shipped->events, 1)];

        return new RecordingFormat(StoredSerializerFactory::create())->write(
            new Recording($shipped->reviewedAt, $shipped->intaktVersion, $shipped->models, $shipped->project, $shipped->change, $events, $shipped->updates),
        );
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
