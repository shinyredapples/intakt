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
#[UsesClass(JourneyInputFailed::class)]
final class UsageReaderTest extends TestCase
{
    private const string ROW = '{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5}';

    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/intakt-usage-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->file);
    }

    public function testTheTokensOfAllRowsAreAdded(): void
    {
        file_put_contents($this->file, self::ROW."\n\n".self::ROW."\n");

        self::assertSame(10, new UsageReader()->tokensIn($this->file));
    }

    #[DataProvider('refusedLines')]
    public function testARowThatIsNotExactlyTheFiveNamedFieldsIsRefused(string $line, string $reason): void
    {
        file_put_contents($this->file, self::ROW."\n".$line."\n");

        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessage($reason);

        new UsageReader()->tokensIn($this->file);
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function refusedLines(): Iterator
    {
        yield 'a limit beyond the five' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": 5, "limit": 9}', 'it must hold exactly'];
        yield 'a missing field' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "tokens": 5}', 'it must hold exactly'];
        yield 'not json' => ['{', 'it must hold exactly'];
        yield 'negative tokens' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": -1}', 'tokens must be'];
        yield 'text tokens' => ['{"day": "2026-10-27", "vendor": "claude", "account": "claude-1", "lane": "build", "tokens": "5"}', 'tokens must be'];
        yield 'an empty account' => ['{"day": "2026-10-27", "vendor": "claude", "account": "", "lane": "build", "tokens": 5}', 'must be text'];
    }

    public function testAnUnreadableFileIsRefused(): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessage('cannot read');

        new UsageReader()->tokensIn($this->file);
    }
}
