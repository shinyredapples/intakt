<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Infrastructure\StoredFindingNormalizer;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[CoversClass(StoredFindingNormalizer::class)]
#[UsesClass(Finding::class)]
#[UsesClass(FindingIds::class)]
#[UsesClass(StoredSerializerFactory::class)]
final class StoredFindingNormalizerTest extends TestCase
{
    public function testAFindingIsWrittenWithItsMergedIds(): void
    {
        self::assertSame(['F2'], $this->normalized([])['merged']);
    }

    public function testACallerThatNamesItsFieldsWithoutMergedGetsNoMergedIds(): void
    {
        $normalized = $this->normalized([AbstractNormalizer::ATTRIBUTES => ['id', 'text']]);

        self::assertSame(['id' => 'F1', 'text' => 'Broken.'], $normalized);
    }

    public function testACallerThatNamesMergedAmongItsFieldsGetsTheMergedIds(): void
    {
        $normalized = $this->normalized([AbstractNormalizer::ATTRIBUTES => ['id', 'merged']]);

        self::assertSame(['id' => 'F1', 'merged' => ['F2']], $normalized);
    }

    public function testACallerThatLeavesMergedOutGetsNoMergedIds(): void
    {
        $normalized = $this->normalized([AbstractNormalizer::IGNORED_ATTRIBUTES => ['merged']]);

        self::assertArrayNotHasKey('merged', $normalized);
        self::assertSame('F1', $normalized['id']);
    }

    public function testAFindingWithAJudgeNoteIsWrittenWithIt(): void
    {
        $normalized = $this->normalized([], $this->finding()->withJudgeNote('Rejected: covered by F2.'));

        self::assertSame('Rejected: covered by F2.', $normalized['judge_note']);
    }

    public function testAFindingWithoutAJudgeNoteIsWrittenWithoutTheKey(): void
    {
        self::assertArrayNotHasKey('judge_note', $this->normalized([]));
    }

    public function testACallerThatNamesItsFieldsWithoutJudgeNoteGetsNoJudgeNote(): void
    {
        $normalized = $this->normalized([AbstractNormalizer::ATTRIBUTES => ['id']], $this->finding()->withJudgeNote('Deferred.'));

        self::assertSame(['id' => 'F1'], $normalized);
    }

    public function testACallerThatLeavesJudgeNoteOutGetsNoJudgeNote(): void
    {
        $normalized = $this->normalized([AbstractNormalizer::IGNORED_ATTRIBUTES => ['judge_note']], $this->finding()->withJudgeNote('Deferred.'));

        self::assertArrayNotHasKey('judge_note', $normalized);
        self::assertSame(['F2'], $normalized['merged']);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<mixed>
     */
    private function normalized(array $context, ?Finding $finding = null): array
    {
        $finding ??= $this->finding();
        $serializer = StoredSerializerFactory::create();
        self::assertInstanceOf(NormalizerInterface::class, $serializer);

        $normalized = $serializer->normalize($finding, JsonEncoder::FORMAT, $context);
        self::assertIsArray($normalized);

        return $normalized;
    }

    private function finding(): Finding
    {
        return new Finding('F1', Severity::P1, 'src/A.php', 7, null, 'Broken.', FixableBy::Builder, false, null, null, merged: new FindingIds('F2'));
    }
}
