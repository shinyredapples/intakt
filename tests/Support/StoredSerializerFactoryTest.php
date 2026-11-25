<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\P1Keys;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Infrastructure\StoredFindingIdsNormalizer;
use Intakt\Core\Review\Infrastructure\StoredFindingNormalizer;
use Intakt\Core\Review\Infrastructure\StoredJsonEncoder;
use Intakt\Core\Review\Infrastructure\StoredP1KeysNormalizer;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(StoredSerializerFactory::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(JudgeDoneFinding::class)]
#[UsesClass(Finding::class)]
#[UsesClass(StoredFindingNormalizer::class)]
#[UsesClass(StoredFindingIdsNormalizer::class)]
#[UsesClass(FindingIds::class)]
#[UsesClass(StoredJsonEncoder::class)]
#[UsesClass(StoredP1KeysNormalizer::class)]
#[UsesClass(P1Keys::class)]
final class StoredSerializerFactoryTest extends KernelTestCase
{
    // A started row outside a round, because it needs the enum, the name converter and the attribute context the container's serializer has.
    public function testTheFactoryWritesAPayloadAsTheContainersStoredSerializerDoes(): void
    {
        $payload = new UsageStarted('a call', 'started', 'alpha', Tier::Strong);
        $stored = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $stored);

        self::assertSame($stored->serialize($payload, JsonEncoder::FORMAT), StoredSerializerFactory::create()->serialize($payload, JsonEncoder::FORMAT));
    }

    // A judge_done row, because reading it back needs the list of finding objects the container's serializer denormalizes.
    public function testTheFactoryReadsAPayloadBackAsTheContainersStoredSerializerDoes(): void
    {
        $json = '{"round":"r1","act":["F1"],"follow_ups":["F2"],"findings":[{"id":"F1","severity":"P1","text":"Broken.","merged":["F3"]}]}';
        $stored = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $stored);

        self::assertEquals($stored->deserialize($json, JudgeDone::class, JsonEncoder::FORMAT), StoredSerializerFactory::create()->deserialize($json, JudgeDone::class, JsonEncoder::FORMAT));
    }

    // A judge_done row with a populated p1_keys map, because only the stored P1 keys normalizer reads it.
    public function testTheFactoryReadsAPopulatedP1KeysMapAsTheContainersStoredSerializerDoes(): void
    {
        $json = '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[],"p1_keys":{"F1":"abc","F2":null}}';
        $stored = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $stored);
        $read = StoredSerializerFactory::create()->deserialize($json, JudgeDone::class, JsonEncoder::FORMAT);

        self::assertSame('abc', $read->p1Keys->keyOf('F1'));
        self::assertEquals($stored->deserialize($json, JudgeDone::class, JsonEncoder::FORMAT), $read);
    }

    // A judge_done row with id lists, because only the stored finding ids normalizer writes them.
    public function testTheFactoryWritesAJudgeDoneRowAsTheContainersStoredSerializerDoes(): void
    {
        $done = new JudgeDone('r1', new FindingIds('F1', 'F3'), new FindingIds('F2'), [new JudgeDoneFinding('F1', Severity::P1, 'Broken.', new FindingIds('F3'))]);
        $stored = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $stored);

        self::assertSame($stored->serialize($done, JsonEncoder::FORMAT), StoredSerializerFactory::create()->serialize($done, JsonEncoder::FORMAT));
    }

    // A finding with merged ids, because only the stored finding normalizer keeps them.
    public function testTheFactoryWritesAFindingWithMergedIdsAsTheContainersStoredSerializerDoes(): void
    {
        $finding = new Finding('F1', Severity::P1, 'src/A.php', 7, null, 'Broken.', FixableBy::Builder, false, null, null, merged: new FindingIds('F2'));
        $stored = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $stored);

        self::assertSame($stored->serialize($finding, JsonEncoder::FORMAT), StoredSerializerFactory::create()->serialize($finding, JsonEncoder::FORMAT));
    }
}
