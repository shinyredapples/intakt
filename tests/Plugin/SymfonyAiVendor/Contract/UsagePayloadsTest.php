<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
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
        $json = $this->serializer()->serialize(new UsageStarted('a call', 'started', 'alpha', Tier::Strong, self::ROUND, 'big'), JsonEncoder::FORMAT);

        self::assertSame('{"call":"a call","phase":"started","project":"alpha","tier":"strong","round":"'.self::ROUND.'","model":"big"}', $json);
    }

    public function testAStartedRowOfAReviewerNamesTheReviewerAndReadsBack(): void
    {
        $started = new UsageStarted('a call', 'started', 'alpha', Tier::Standard, self::ROUND, 'big', 'a-reviewer');

        $json = $this->serializer()->serialize($started, JsonEncoder::FORMAT);

        self::assertSame('{"call":"a call","phase":"started","project":"alpha","tier":"standard","round":"'.self::ROUND.'","model":"big","reviewer":"a-reviewer"}', $json);
        self::assertEquals($started, $this->serializer()->deserialize($json, UsageStarted::class, JsonEncoder::FORMAT));
    }

    public function testAStartedRowOutsideARoundHasNoRoundKey(): void
    {
        $json = $this->serializer()->serialize(new UsageStarted('a call', 'started', 'alpha', Tier::Light, model: 'big'), JsonEncoder::FORMAT);

        self::assertSame('{"call":"a call","phase":"started","project":"alpha","tier":"light","model":"big"}', $json);
    }

    public function testAStartedRowInTodaysFormatReadsBack(): void
    {
        $row = '{"call":"a call","phase":"started","project":"alpha","tier":"standard","round":"'.self::ROUND.'","model":"big"}';

        $read = $this->serializer()->deserialize($row, UsageStarted::class, JsonEncoder::FORMAT);

        self::assertEquals(new UsageStarted('a call', 'started', 'alpha', Tier::Standard, self::ROUND, 'big'), $read);
    }

    public function testAStartedRowWrittenBeforeTheModelWasKeptReadsBackWithoutOne(): void
    {
        $row = '{"call":"a call","phase":"started","project":"alpha","tier":"standard","round":"'.self::ROUND.'"}';

        $read = $this->serializer()->deserialize($row, UsageStarted::class, JsonEncoder::FORMAT);

        self::assertNull($read->model);
    }

    public function testASettledRowKeepsCacheCreationAndTheNullTokenFields(): void
    {
        $json = $this->serializer()->serialize(new UsageSettled('a call', 'settled', [$this->usage()]), JsonEncoder::FORMAT);

        self::assertSame('{"call":"a call","phase":"settled","usages":['.self::TODAYS_USAGE.']}', $json);
    }

    public function testASettledRowInTodaysFormatReadsBack(): void
    {
        $row = '{"call":"a call","phase":"settled","usages":['.self::TODAYS_USAGE.']}';

        $read = $this->serializer()->deserialize($row, UsageSettled::class, JsonEncoder::FORMAT);

        self::assertEquals(new UsageSettled('a call', 'settled', [$this->usage()]), $read);
    }

    public function testAnUnsettledRowWrittenThroughTheSerializerReadsBackEqual(): void
    {
        $unsettled = new UsageUnsettled('a call', 'unsettled', 'RuntimeException', [$this->usage()]);

        $read = $this->serializer()->deserialize($this->serializer()->serialize($unsettled, JsonEncoder::FORMAT), UsageUnsettled::class, JsonEncoder::FORMAT);

        self::assertEquals($unsettled, $read);
    }

    public function testAnUnsettledRowInTodaysFormatReadsBack(): void
    {
        $row = '{"call":"a call","phase":"unsettled","failure":"RuntimeException","usages":[]}';

        $read = $this->serializer()->deserialize($row, UsageUnsettled::class, JsonEncoder::FORMAT);

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
