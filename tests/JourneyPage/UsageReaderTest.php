<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(UsageReader::class)]
#[CoversClass(Usage::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UsageAccount::class)]
#[UsesClass(UsageLane::class)]
#[UsesClass(UsageVendor::class)]
#[UsesClass(JourneyInputFailed::class)]
final class UsageReaderTest extends TestCase
{
    private const string ROW = '{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5, "reserved": 0}';

    private string $file = '';

    public function testTheTokensOfAllRowsAreAdded(): void
    {
        file_put_contents($this->file, self::ROW."\n\n".self::ROW."\n");

        self::assertSame(10, new UsageReader()->usageIn($this->file)->total());
    }

    public function testTheSixFieldsAreAcceptedInAnyOrder(): void
    {
        file_put_contents($this->file, '{"reserved": 0, "tokens": 5, "day": "2026-11-13", "vendor": "gpt", "account": "gpt-1", "lane": "review"}');

        self::assertSame(5, new UsageReader()->usageIn($this->file)->total());
    }

    public function testReservedTokensAreAddedApartAndNeverToTheMeasuredTokens(): void
    {
        file_put_contents($this->file, self::ROW."\n".'{"day": "2026-11-01", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 3, "reserved": 4}'."\n");

        $usage = new UsageReader()->usageIn($this->file);

        self::assertSame([8, 4], [$usage->total(), $usage->reserved()]);
    }

    public function testARowKeepsItsDayVendorAccountAndLane(): void
    {
        file_put_contents($this->file, '{"day": "2026-11-13", "vendor": "claude", "account": "claude-api", "lane": "review", "tokens": 7, "reserved": 0}');

        $row = new UsageReader()->usageIn($this->file)->rows[0];

        self::assertSame(['2026-11-13', UsageVendor::Claude, UsageAccount::ClaudeApiKey, UsageLane::Review], [$row->day->format('Y-m-d'), $row->vendor, $row->account, $row->lane]);
    }

    #[DataProvider('refusedLines')]
    public function testARowThatIsNotExactlyTheSixNamedFieldsIsRefused(string $line, string $reason): void
    {
        file_put_contents($this->file, self::ROW."\n".$line."\n");

        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/'.preg_quote('usage line 2 is refused: '.$reason, '/').'/');

        new UsageReader()->usageIn($this->file);
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function refusedLines(): Iterator
    {
        yield 'a limit beyond the six' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5, "reserved": 0, "limit": 9}', 'it must hold exactly'];
        yield 'a missing field' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "tokens": 5, "reserved": 0}', 'it must hold exactly'];
        yield 'a missing reservation' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5}', 'it must hold exactly day, vendor, account, lane, tokens, reserved'];
        yield 'not json' => ['{', 'it must hold exactly'];
        yield 'negative tokens' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": -1, "reserved": 0}', 'tokens must be'];
        yield 'text tokens' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": "5", "reserved": 0}', 'tokens must be'];
        yield 'a negative reservation' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5, "reserved": -1}', 'reserved must be a whole number of zero or more'];
        yield 'a fractional reservation' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5, "reserved": 0.5}', 'reserved must be a whole number of zero or more'];
        yield 'an empty account' => ['{"day": "2026-10-27", "vendor": "claude", "account": "", "lane": "build", "tokens": 5, "reserved": 0}', 'day, vendor, account and lane must be text'];
        yield 'an account named by email' => ['{"day": "2026-10-27", "vendor": "claude", "account": "someone@example.com", "lane": "build", "tokens": 5, "reserved": 0}', 'account must be one of claude-1, claude-2, claude-api, gpt-1'];
        yield 'an unknown vendor' => ['{"day": "2026-10-27", "vendor": "other", "account": "claude-1", "lane": "build", "tokens": 5, "reserved": 0}', 'vendor must be one of claude, gpt'];
        yield 'an unknown lane' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "plan", "tokens": 5, "reserved": 0}', 'lane must be one of build, review'];
        yield 'a day that does not exist' => ['{"day": "2026-02-30", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5, "reserved": 0}', 'day must be a date'];
        yield 'a day with a time' => ['{"day": "2026-10-27T10:00", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5, "reserved": 0}', 'day must be a date'];
    }

    public function testAnUnreadableFileIsRefused(): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/'.preg_quote('cannot read', '/').'/');

        new UsageReader()->usageIn($this->file);
    }

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/intakt-usage-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->file);
    }
}
