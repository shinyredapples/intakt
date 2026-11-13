<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Infrastructure\StoredFindingNormalizer;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[CoversClass(StoredFindingNormalizer::class)]
#[UsesClass(Finding::class)]
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

    /**
     * @param array<string, mixed> $context
     *
     * @return array<mixed>
     */
    private function normalized(array $context): array
    {
        $finding = new Finding('F1', Severity::P1, 'src/A.php', 7, null, 'Broken.', FixableBy::Builder, false, null, null, merged: ['F2']);
        $serializer = StoredSerializerFactory::create();
        self::assertInstanceOf(NormalizerInterface::class, $serializer);

        $normalized = $serializer->normalize($finding, 'json', $context);
        self::assertIsArray($normalized);

        return $normalized;
    }
}
