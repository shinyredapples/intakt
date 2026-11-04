<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageUnsettled;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(UsageStarted::class)]
#[CoversClass(UsageSettled::class)]
#[CoversClass(UsageUnsettled::class)]
#[CoversClass(TokenUsageRow::class)]
final class UsagePayloadsTest extends KernelTestCase
{
    private const string ROUND = '0195f0a2-7c1e-7000-8000-000000000001';

    private const string TODAYS_USAGE = '{"account":"anthropic.default","model":"big","prompt":100,"completion":20,"thinking":null,"tool":null,"cached":null,"cache_creation":5,"cache_read":null,"total":125,"remaining":null,"remaining_minute":null,"remaining_month":null}';

    public function testAStartedRowOfARoundIsWrittenWithTodaysKeysAndTierValue(): void
    {
        $json = $this->serializer()->serialize(new UsageStarted('a call', 'started', 'alpha', Tier::Strong, self::ROUND), 'json');

        self::assertSame('{"call":"a call","phase":"started","project":"alpha","tier":"strong","round":"'.self::ROUND.'"}', $json);
    }

    public function testAStartedRowOutsideARoundHasNoRoundKey(): void
    {
        $json = $this->serializer()->serialize(new UsageStarted('a call', 'started', 'alpha', Tier::Light), 'json');

        self::assertSame('{"call":"a call","phase":"started","project":"alpha","tier":"light"}', $json);
    }

    public function testAStartedRowInTodaysFormatReadsBack(): void
    {
        $row = '{"call":"a call","phase":"started","project":"alpha","tier":"standard","round":"'.self::ROUND.'"}';

        $read = $this->serializer()->deserialize($row, UsageStarted::class, 'json');

        self::assertEquals(new UsageStarted('a call', 'started', 'alpha', Tier::Standard, self::ROUND), $read);
    }

    public function testASettledRowKeepsCacheCreationAndTheNullTokenFields(): void
    {
        $json = $this->serializer()->serialize(new UsageSettled('a call', 'settled', [$this->usage()]), 'json');

        self::assertSame('{"call":"a call","phase":"settled","usages":['.self::TODAYS_USAGE.']}', $json);
    }

    public function testASettledRowInTodaysFormatReadsBack(): void
    {
        $row = '{"call":"a call","phase":"settled","usages":['.self::TODAYS_USAGE.']}';

        $read = $this->serializer()->deserialize($row, UsageSettled::class, 'json');

        self::assertEquals(new UsageSettled('a call', 'settled', [$this->usage()]), $read);
    }

    public function testAnUnsettledRowWrittenThroughTheSerializerReadsBackEqual(): void
    {
        $unsettled = new UsageUnsettled('a call', 'unsettled', 'RuntimeException', [$this->usage()]);

        $read = $this->serializer()->deserialize($this->serializer()->serialize($unsettled, 'json'), UsageUnsettled::class, 'json');

        self::assertEquals($unsettled, $read);
    }

    public function testAnUnsettledRowInTodaysFormatReadsBack(): void
    {
        $row = '{"call":"a call","phase":"unsettled","failure":"RuntimeException","usages":[]}';

        $read = $this->serializer()->deserialize($row, UsageUnsettled::class, 'json');

        self::assertEquals(new UsageUnsettled('a call', 'unsettled', 'RuntimeException', []), $read);
    }

    private function usage(): TokenUsageRow
    {
        return new TokenUsageRow('anthropic.default', 'big', 100, 20, null, null, null, 5, null, 125, null, null, null);
    }

    private function serializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
    }
}
