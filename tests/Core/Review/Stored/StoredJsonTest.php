<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Stored;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingStatus;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Serializer\SerializerInterface;

// Covers nothing because the subject is the application's configured serializer for stored JSON, not one class.
#[CoversNothing]
final class StoredJsonTest extends KernelTestCase
{
    private const string TODAYS_FINDING = '{"id":"a","severity":"P2","file":"src/A.php","line":7,"symbol":null,"text":"Broken.","fixable_by":"builder","repeat":false,"requirement":null,"evidence":{"probe":"php -l","output":"ok"},"regression":true}';

    public function testAVerdictWrittenThroughTheSerializerReadsBackEqual(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding()], ['b'], ['later']);

        $read = $this->serializer()->deserialize($this->serializer()->serialize($verdict, 'json'), Verdict::class, 'json');

        self::assertEquals($verdict, $read);
    }

    public function testAStoredVerdictKeepsTheSnakeCaseKeysOfTheWireFormat(): void
    {
        $json = $this->serializer()->serialize(new Verdict(Decision::Changes, [$this->finding()], [], ['later']), 'json');

        /** @var array<string, mixed> $stored */
        $stored = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($stored);
        self::assertSame(['verdict', 'findings', 'resolved', 'follow_ups'], $keys);
        self::assertStringContainsString('"fixable_by":"builder"', $json);
    }

    public function testTrackedFindingsWrittenThroughTheSerializerReadBackWithTheirRoundsAndStatus(): void
    {
        $tracked = [new TrackedFinding('a', $this->finding(), [1, 3], FindingStatus::Resolved)];

        $read = $this->serializer()->deserialize($this->serializer()->serialize($tracked, 'json'), TrackedFinding::class.'[]', 'json');

        self::assertEquals($tracked, $read);
    }

    public function testATrackedFindingIsWrittenWithTheFieldsJsonEncodeWrote(): void
    {
        $tracked = [new TrackedFinding('a', $this->finding(), [1, 3], FindingStatus::Open)];

        self::assertSame(json_encode($tracked, JSON_THROW_ON_ERROR), $this->serializer()->serialize($tracked, 'json'));
    }

    public function testAFindingWithoutEvidenceKeepsTheNullKeyThatJsonEncodeWrote(): void
    {
        $finding = new Finding('a', Severity::P3, null, null, null, 'Text.', FixableBy::Owner, true, null, null);

        $json = $this->serializer()->serialize($finding, 'json');

        self::assertSame(json_encode($finding, JSON_THROW_ON_ERROR), $json);
    }

    public function testAFindingRowWrittenByJsonEncodeStillReadsBack(): void
    {
        $read = $this->serializer()->deserialize('['.self::TODAYS_FINDING.']', Finding::class.'[]', 'json');

        self::assertEquals([$this->finding()], $read);
    }

    public function testAVerdictRowWrittenByJsonEncodeStillReadsBack(): void
    {
        $row = '{"verdict":"changes","findings":['.self::TODAYS_FINDING.'],"resolved":["b"],"follow_ups":["later"]}';

        $read = $this->serializer()->deserialize($row, Verdict::class, 'json');

        self::assertEquals(new Verdict(Decision::Changes, [$this->finding()], ['b'], ['later']), $read);
    }

    public function testATrackedFindingRowWrittenByJsonEncodeStillReadsBack(): void
    {
        $row = '[{"id":"a","latest":'.self::TODAYS_FINDING.',"rounds":[1,3],"status":"resolved"}]';

        $read = $this->serializer()->deserialize($row, TrackedFinding::class.'[]', 'json');

        self::assertEquals([new TrackedFinding('a', $this->finding(), [1, 3], FindingStatus::Resolved)], $read);
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
