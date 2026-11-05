<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageStarted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(StoredSerializerFactory::class)]
#[UsesClass(UsageStarted::class)]
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
}
