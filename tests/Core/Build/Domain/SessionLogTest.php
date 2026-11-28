<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\BuildUsage;
use Intakt\Core\Build\Domain\SessionLog;
use Intakt\Core\Build\Domain\SessionUsage;
use Intakt\Core\Build\Domain\UsageReading;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionLog::class)]
#[CoversClass(SessionUsage::class)]
#[CoversClass(BuildUsage::class)]
#[CoversClass(UsageReading::class)]
final class SessionLogTest extends TestCase
{
    private const string USAGE = '"usage":{"input_tokens":10,"output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}';

    public function testTheLastResultOfTheLogGivesTheUsage(): void
    {
        $usage = SessionLog::usageIn('{"type":"result","usage":{"input_tokens":1,"output_tokens":1,"cache_read_input_tokens":1,"cache_creation_input_tokens":1}}'."\nnoise\n{\"type\":\"result\",".self::USAGE."}\nclaude exited with 0\n");

        self::assertEquals(new BuildUsage(10, 20, 30, 40), $usage->usage);
        self::assertSame(100, $usage->usage?->total());
    }

    public function testALogWithoutAResultReportsNoUsage(): void
    {
        $usage = SessionLog::usageIn('{"type":"assistant",'.self::USAGE."}\nclaude cut after 3000 seconds, its commits are kept\n");

        self::assertSame(UsageReading::NotReported, $usage->reading);
        self::assertNull($usage->usage);
    }

    #[TestWith(['"usage":{"input_tokens":-1,"output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}'])]
    #[TestWith(['"usage":{"input_tokens":1.5,"output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}'])]
    #[TestWith(['"usage":{"input_tokens":"10","output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}'])]
    #[TestWith(['"usage":{"output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}'])]
    #[TestWith(['"usage":{"input_tokens":1000000000000001,"output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}'])]
    #[TestWith(['"usage":[10,20,30,40]'])]
    #[TestWith(['"usage":null'])]
    public function testALastResultWithoutValidCountsIsMalformed(string $usage): void
    {
        $read = SessionLog::usageIn('{"type":"result",'.self::USAGE."}\n{\"type\":\"result\",{$usage}}\n");

        self::assertSame(UsageReading::Malformed, $read->reading);
        self::assertNull($read->usage);
    }

    public function testACountAtTheUpperBoundIsStillRead(): void
    {
        $usage = SessionLog::usageIn('{"type":"result","usage":{"input_tokens":1000000000000000,"output_tokens":0,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}');

        self::assertSame(BuildUsage::MOST, $usage->usage?->inputTokens);
    }

    public function testAResultNestedDeeperThanTheDecoderAllowsIsNoResult(): void
    {
        $usage = SessionLog::usageIn('{"type":"result",'.self::USAGE.',"deep":'.str_repeat('[', 20).str_repeat(']', 20).'}');

        self::assertSame(UsageReading::NotReported, $usage->reading);
    }

    public function testALineOfExactlyTheLineLimitIsAccepted(): void
    {
        $usage = SessionLog::usageIn(str_repeat('x', SessionLog::MAX_LINE_BYTES)."\n{\"type\":\"result\",".self::USAGE.'}');

        self::assertSame(UsageReading::Read, $usage->reading);
    }

    public function testALineOneByteOverTheLineLimitIsRefused(): void
    {
        $usage = SessionLog::usageIn('{"type":"result",'.self::USAGE."}\n".str_repeat('x', SessionLog::MAX_LINE_BYTES + 1));

        self::assertSame(UsageReading::LineTooLong, $usage->reading);
    }

    public function testTheReadersAnswerGivesBackTheSameUsage(): void
    {
        $usage = SessionUsage::read(new BuildUsage(1, 2, 3, 4));

        self::assertEquals($usage, SessionUsage::fromAnswer($usage->answer()));
    }

    #[TestWith(['{"reading":"too_large"}'])]
    #[TestWith(['{"reading":"reader_failed"}'])]
    #[TestWith(['{"reading":"unknown"}'])]
    #[TestWith(['{"reading":"read"}'])]
    #[TestWith(['{"reading":"not_reported","x":{"y":1}}'])]
    #[TestWith(['not json'])]
    #[TestWith(['["read"]'])]
    public function testAReaderAnswerItCannotGiveMeansTheReaderFailed(string $answer): void
    {
        self::assertSame(UsageReading::ReaderFailed, SessionUsage::fromAnswer($answer)->reading);
    }

    #[TestWith(['not_reported', UsageReading::NotReported])]
    #[TestWith(['malformed', UsageReading::Malformed])]
    #[TestWith(['line_too_long', UsageReading::LineTooLong])]
    public function testAReaderAnswerWithoutUsageKeepsItsReading(string $value, UsageReading $reading): void
    {
        self::assertSame($reading, SessionUsage::fromAnswer('{"reading":"'.$value.'"}')->reading);
    }

    public function testAUsageWithoutCountsCannotBeMarkedAsRead(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SessionUsage::withoutUsage(UsageReading::Read);
    }

    public function testTheLedgerPayloadNamesTheReadingAndEachCount(): void
    {
        self::assertSame(
            ['reading' => 'read', 'input_tokens' => 1, 'output_tokens' => 2, 'cache_read_input_tokens' => 3, 'cache_creation_input_tokens' => 4],
            SessionUsage::read(new BuildUsage(1, 2, 3, 4))->payload(),
        );
        self::assertSame(
            ['reading' => 'too_large', 'input_tokens' => null, 'output_tokens' => null, 'cache_read_input_tokens' => null, 'cache_creation_input_tokens' => null],
            SessionUsage::withoutUsage(UsageReading::TooLarge)->payload(),
        );
    }
}
