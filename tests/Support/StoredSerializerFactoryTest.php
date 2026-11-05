<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageStarted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(StoredSerializerFactory::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(JudgeDoneFinding::class)]
final class StoredSerializerFactoryTest extends KernelTestCase
{
    // A started row outside a round, because it needs the enum, the name converter and the attribute context the container's serializer has.
    public function testTheFactoryWritesAPayloadAsTheContainersStoredSerializerDoes(): void
    {
        $payload = new UsageStarted('a call', 'started', 'alpha', Tier::Strong);
        $stored = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $stored);

        self::assertSame($stored->serialize($payload, 'json'), StoredSerializerFactory::create()->serialize($payload, 'json'));
    }

    // A judge_done row, because reading it back needs the list of finding objects the container's serializer denormalizes.
    public function testTheFactoryReadsAPayloadBackAsTheContainersStoredSerializerDoes(): void
    {
        $json = '{"round":"r1","act":["F1"],"follow_ups":["F2"],"findings":[{"id":"F1","severity":"P1","text":"Broken."}]}';
        $stored = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $stored);

        self::assertEquals($stored->deserialize($json, JudgeDone::class, 'json'), StoredSerializerFactory::create()->deserialize($json, JudgeDone::class, 'json'));
    }
}
