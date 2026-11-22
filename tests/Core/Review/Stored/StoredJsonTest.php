<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Stored;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingStatus;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

// Covers nothing because the subject is the application's configured serializer for stored JSON, not one class.
#[CoversNothing]
final class StoredJsonTest extends KernelTestCase
{
    private const string TODAYS_FINDING = '{"id":"a","severity":"P2","file":"src/A.php","line":7,"symbol":null,"text":"Broken.","fixable_by":"builder","repeat":false,"requirement":null,"evidence":{"probe":"php -l","output":"ok"},"regression":true}';

    // Written by the stored serializer on main before the answer document existed, so it pins the stored round JSON.
    private const string VERDICT_FROM_MAIN = '{"verdict":"changes","findings":[{"id":"a","severity":"P2","file":"src\/A.php","line":7,"symbol":null,"text":"Broken.","fixable_by":"builder","repeat":false,"requirement":null,"evidence":{"probe":"php -l","output":"ok"},"regression":true,"merged":[]}],"resolved":["b"],"follow_ups":["later"]}';

    public function testAVerdictWrittenThroughTheSerializerReadsBackEqual(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding()], [new ResolvedFinding('b')], [new FollowUpNote('later')]);

        $read = $this->serializer()->deserialize($this->serializer()->serialize(ReviewerAnswer::fromVerdict($verdict), JsonEncoder::FORMAT), ReviewerAnswer::class, JsonEncoder::FORMAT)->toVerdict();

        self::assertEquals($verdict, $read);
    }

    public function testAStoredVerdictKeepsTheSnakeCaseKeysOfTheWireFormat(): void
    {
        $json = $this->serializer()->serialize(ReviewerAnswer::fromVerdict(new Verdict(Decision::Changes, [$this->finding()], [], [new FollowUpNote('later')])), JsonEncoder::FORMAT);

        /** @var array<string, mixed> $stored */
        $stored = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($stored);
        self::assertSame(['verdict', 'findings', 'resolved', 'follow_ups'], $keys);
        self::assertStringContainsString('"fixable_by":"builder"', $json);
    }

    public function testTrackedFindingsWrittenThroughTheSerializerReadBackWithTheirRoundsAndStatus(): void
    {
        $tracked = [new TrackedFinding('a', $this->finding(), [1, 3], FindingStatus::Resolved)];

        $read = $this->serializer()->deserialize($this->serializer()->serialize($tracked, JsonEncoder::FORMAT), TrackedFinding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals($tracked, $read);
    }

    public function testATrackedFindingIsWrittenWithTheFieldsJsonEncodeWrote(): void
    {
        $tracked = [new TrackedFinding('a', $this->finding(), [1, 3], FindingStatus::Open)];

        self::assertSame(json_encode($tracked, JSON_THROW_ON_ERROR), $this->serializer()->serialize($tracked, JsonEncoder::FORMAT));
    }

    public function testAFindingWithoutEvidenceKeepsTheNullKeyThatJsonEncodeWrote(): void
    {
        $finding = new Finding('a', Severity::P3, null, null, null, 'Text.', FixableBy::Owner, true, null, null);

        $json = $this->serializer()->serialize($finding, JsonEncoder::FORMAT);

        self::assertSame(json_encode($finding, JSON_THROW_ON_ERROR), $json);
    }

    public function testAFindingRowWrittenByJsonEncodeStillReadsBack(): void
    {
        $read = $this->serializer()->deserialize('['.self::TODAYS_FINDING.']', Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals([$this->finding()], $read);
    }

    public function testAVerdictRowWrittenByJsonEncodeStillReadsBack(): void
    {
        $row = '{"verdict":"changes","findings":['.self::TODAYS_FINDING.'],"resolved":["b"],"follow_ups":["later"]}';

        $read = $this->serializer()->deserialize($row, ReviewerAnswer::class, JsonEncoder::FORMAT)->toVerdict();

        self::assertEquals(new Verdict(Decision::Changes, [$this->finding()], [new ResolvedFinding('b')], [new FollowUpNote('later')]), $read);
    }

    public function testARoundStoredBeforeTheAnswerDocumentStillDecodesToTheSameVerdict(): void
    {
        $read = $this->serializer()->deserialize(self::VERDICT_FROM_MAIN, ReviewerAnswer::class, JsonEncoder::FORMAT);

        self::assertEquals(new Verdict(Decision::Changes, [$this->finding()], [new ResolvedFinding('b')], [new FollowUpNote('later')]), $read->toVerdict());
    }

    public function testEncodingAVerdictGivesTheSameJsonAsBeforeTheAnswerDocument(): void
    {
        $json = $this->serializer()->serialize(ReviewerAnswer::fromVerdict(new Verdict(Decision::Changes, [$this->finding()], [new ResolvedFinding('b')], [new FollowUpNote('later')])), JsonEncoder::FORMAT);

        self::assertSame(self::VERDICT_FROM_MAIN, $json);
    }

    public function testATrackedFindingRowWrittenByJsonEncodeStillReadsBack(): void
    {
        $row = '[{"id":"a","latest":'.self::TODAYS_FINDING.',"rounds":[1,3],"status":"resolved"}]';

        $read = $this->serializer()->deserialize($row, TrackedFinding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals([new TrackedFinding('a', $this->finding(), [1, 3], FindingStatus::Resolved)], $read);
    }

    public function testAFindingWithMergedIdsReadsBackWithThem(): void
    {
        $finding = $this->finding()->withMerged(new FindingIds('F2', 'F3'));

        $read = $this->serializer()->deserialize($this->serializer()->serialize([$finding], JsonEncoder::FORMAT), Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals([$finding], $read);
    }

    public function testATrackedFindingWithMergedIdsReadsBackWithThem(): void
    {
        $tracked = [new TrackedFinding('a', $this->finding()->withMerged(new FindingIds('F2')), [1], FindingStatus::Open)];

        $read = $this->serializer()->deserialize($this->serializer()->serialize($tracked, JsonEncoder::FORMAT), TrackedFinding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals($tracked, $read);
    }

    public function testAFindingIsWrittenWithItsMergedIdsUnderTheKeyMerged(): void
    {
        $json = $this->serializer()->serialize($this->finding()->withMerged(new FindingIds('F2')), JsonEncoder::FORMAT);

        self::assertStringEndsWith(',"regression":true,"gate_gap":false,"merged":["F2"]}', $json);
    }

    public function testAFindingMarkedAsAGateGapIsWrittenWithTheMarkerAndReadsBackWithIt(): void
    {
        $finding = new Finding('a', Severity::P3, 'src/A.php', 7, null, 'The gate misses a static closure.', FixableBy::Owner, false, 'phpstan', null, gate_gap: true);

        $json = $this->serializer()->serialize([$finding], JsonEncoder::FORMAT);
        $read = $this->serializer()->deserialize($json, Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertStringContainsString('"regression":false,"gate_gap":true,', $json);
        self::assertEquals([$finding], $read);
    }

    public function testATrackedFindingMarkedAsAGateGapReadsBackWithTheMarker(): void
    {
        $gap = new Finding('a', Severity::P3, null, null, null, 'The gate misses a case.', FixableBy::Owner, false, 'phpstan', null, gate_gap: true);
        $tracked = [new TrackedFinding('a', $gap, [1], FindingStatus::Open)];

        $read = $this->serializer()->deserialize($this->serializer()->serialize($tracked, JsonEncoder::FORMAT), TrackedFinding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals($tracked, $read);
    }

    public function testAFindingRowWrittenWithoutTheGateGapMarkerReadsAsNotAGateGap(): void
    {
        $read = $this->serializer()->deserialize('['.self::TODAYS_FINDING.']', Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertInstanceOf(Finding::class, $read[0]);
        self::assertFalse($read[0]->gate_gap);
    }

    public function testAFindingRowWithMergedIdsWrittenBeforeTheIdsWereOneValueReadsBackAndIsWrittenAgainUnchanged(): void
    {
        $row = '[{"id":"a","severity":"P2","file":"src\/A.php","line":7,"symbol":null,"text":"Broken.","fixable_by":"builder","repeat":false,"requirement":null,"evidence":{"probe":"php -l","output":"ok"},"regression":true,"gate_gap":false,"merged":["F2","F3"]}]';

        $read = $this->serializer()->deserialize($row, Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertSame($row, $this->serializer()->serialize($read, JsonEncoder::FORMAT));
    }

    public function testAFindingRowWithoutMergedIdsReadsWithNone(): void
    {
        $read = $this->serializer()->deserialize('['.self::TODAYS_FINDING.']', Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertInstanceOf(Finding::class, $read[0]);
        self::assertSame([], [...$read[0]->merged]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenMergedIds(): iterable
    {
        yield 'a string' => ['"F2"'];
        yield 'a number among the ids' => ['["F2",3]'];
        yield 'an object' => ['{"a":"F2"}'];
        yield 'an object with list keys' => ['{"0":"F2"}'];
        yield 'an empty object' => ['{}'];
        yield 'null' => ['null'];
    }

    #[DataProvider('brokenMergedIds')]
    public function testAFindingRowWithMergedIdsThatAreNotAListOfStringsFailsToRead(string $merged): void
    {
        $row = '['.substr(self::TODAYS_FINDING, 0, -1).',"merged":'.$merged.'}]';

        $this->expectException(UnexpectedValueException::class);

        $this->serializer()->deserialize($row, Finding::class.'[]', JsonEncoder::FORMAT);
    }

    public function testAFindingWithAJudgeNoteReadsBackWithIt(): void
    {
        $finding = $this->finding()->withJudgeNote('Rejected: covered by F2.');

        $read = $this->serializer()->deserialize($this->serializer()->serialize([$finding], JsonEncoder::FORMAT), Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals([$finding], $read);
    }

    public function testATrackedFindingWithAJudgeNoteReadsBackWithIt(): void
    {
        $tracked = [new TrackedFinding('a', $this->finding()->withJudgeNote('Deferred: needs #12.'), [1], FindingStatus::Open)];

        $read = $this->serializer()->deserialize($this->serializer()->serialize($tracked, JsonEncoder::FORMAT), TrackedFinding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals($tracked, $read);
    }

    public function testAFindingIsWrittenWithItsJudgeNoteUnderTheKeyJudgeNote(): void
    {
        $json = $this->serializer()->serialize($this->finding()->withJudgeNote('Deferred.'), JsonEncoder::FORMAT);

        self::assertStringEndsWith(',"merged":[],"judge_note":"Deferred."}', $json);
    }

    public function testAFindingWithoutAJudgeNoteIsWrittenWithoutTheKey(): void
    {
        $json = $this->serializer()->serialize($this->finding(), JsonEncoder::FORMAT);

        self::assertStringNotContainsString('judge_note', $json);
    }

    public function testAFindingRowWithoutAJudgeNoteReadsWithNone(): void
    {
        $read = $this->serializer()->deserialize('['.self::TODAYS_FINDING.']', Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertInstanceOf(Finding::class, $read[0]);
        self::assertNull($read[0]->judgeNote());
    }

    public function testAFindingRowWithANullJudgeNoteReadsWithNone(): void
    {
        $row = '['.substr(self::TODAYS_FINDING, 0, -1).',"judge_note":null}]';

        $read = $this->serializer()->deserialize($row, Finding::class.'[]', JsonEncoder::FORMAT);

        self::assertEquals([$this->finding()], $read);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenJudgeNotes(): iterable
    {
        yield 'a number' => ['3'];
        yield 'a list' => ['["Deferred."]'];
        yield 'an object' => ['{"note":"Deferred."}'];
        yield 'a boolean' => ['true'];
    }

    #[DataProvider('brokenJudgeNotes')]
    public function testAFindingRowWhoseJudgeNoteIsNotAStringFailsToRead(string $note): void
    {
        $row = '['.substr(self::TODAYS_FINDING, 0, -1).',"judge_note":'.$note.'}]';

        $this->expectException(UnexpectedValueException::class);

        $this->serializer()->deserialize($row, Finding::class.'[]', JsonEncoder::FORMAT);
    }

    private function finding(): Finding
    {
        return new Finding('a', Severity::P2, 'src/A.php', 7, null, 'Broken.', FixableBy::Builder, false, null, new Evidence('php -l', 'ok'), true);
    }

    private function serializer(): SerializerInterface
    {
        return self::getContainer()->get('serializer.stored');
    }
}
