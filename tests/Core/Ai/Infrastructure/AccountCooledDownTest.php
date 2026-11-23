<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Infrastructure\AccountCooledDown;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(AccountCooledDown::class)]
final class AccountCooledDownTest extends KernelTestCase
{
    private const string TODAYS_ROW = '{"account":"anthropic.default","until":"2026-11-04T12:05:00+00:00"}';

    public function testACooldownIsWrittenWithTodaysKeysAndMoment(): void
    {
        $json = $this->serializer()->serialize(new AccountCooledDown('anthropic.default', new DateTimeImmutable('2026-11-04T12:05:00+00:00')), JsonEncoder::FORMAT);

        self::assertSame(self::TODAYS_ROW, $json);
    }

    public function testACooldownRowInTodaysFormatReadsBack(): void
    {
        $read = $this->serializer()->deserialize(self::TODAYS_ROW, AccountCooledDown::class, JsonEncoder::FORMAT);

        self::assertEquals(new AccountCooledDown('anthropic.default', new DateTimeImmutable('2026-11-04T12:05:00+00:00')), $read);
    }

    private function serializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
    }
}
