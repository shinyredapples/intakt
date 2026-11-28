<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildUsage;
use Intakt\Core\Build\Domain\SessionLog;
use Intakt\Core\Build\Domain\SessionUsage;
use Intakt\Core\Build\Domain\UsageReading;
use Intakt\Core\Build\UseCase\RunBuildJob\IsolatedSessionLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(IsolatedSessionLog::class)]
#[UsesClass(SessionLog::class)]
#[UsesClass(SessionUsage::class)]
#[UsesClass(BuildUsage::class)]
#[UsesClass(UsageReading::class)]
final class IsolatedSessionLogTest extends TestCase
{
    private const string AUTOLOAD = __DIR__.'/../../../../../vendor/autoload.php';

    private const string RESULT = '{"type":"assistant","message":{"id":"msg_01","type":"message","role":"assistant","model":"claude-opus-5-5","content":[{"type":"text","text":"done"}],"usage":{"input_tokens":1200,"output_tokens":340,"cache_read_input_tokens":56000,"cache_creation_input_tokens":7800}}}';

    private string $folder = '';

    public function testTheCliMessagesInABuildLogAreReadInTheChildProcess(): void
    {
        $usage = new IsolatedSessionLog(self::AUTOLOAD)->usageIn("starting\n".self::RESULT."\nclaude exited with 0\n");

        self::assertSame(UsageReading::Read, $usage->reading);
        self::assertEquals(new BuildUsage(1200, 340, 56000, 7800), $usage->usage);
    }

    public function testALogOfExactlyTheByteLimitIsStillRead(): void
    {
        $log = self::RESULT."\n";
        $remaining = IsolatedSessionLog::MAX_BYTES - strlen($log);
        $log .= str_repeat("x\n", intdiv($remaining, 2)).str_repeat('x', $remaining % 2);

        $usage = new IsolatedSessionLog(self::AUTOLOAD)->usageIn($log);

        self::assertSame(IsolatedSessionLog::MAX_BYTES, strlen($log));
        self::assertSame(UsageReading::Read, $usage->reading);
    }

    public function testALogOneByteOverTheLimitIsRefusedWithoutAReader(): void
    {
        $usage = new IsolatedSessionLog($this->autoload('<?php echo "{\"reading\":\"not_reported\"}";'))->usageIn(str_repeat('x', IsolatedSessionLog::MAX_BYTES + 1));

        self::assertSame(UsageReading::TooLarge, $usage->reading);
        self::assertNull($usage->usage);
    }

    public function testALineOverTheLineLimitIsRefusedByTheReader(): void
    {
        $usage = new IsolatedSessionLog(self::AUTOLOAD)->usageIn(self::RESULT."\n".str_repeat('x', SessionLog::MAX_LINE_BYTES + 1)."\n");

        self::assertSame(UsageReading::LineTooLong, $usage->reading);
    }

    public function testTheReaderRunsWithoutTheOrchestratorsEnvironment(): void
    {
        $_ENV['INTAKT_SAMPLE_SECRET'] = 'value';
        putenv('INTAKT_SAMPLE_SECRET=value');

        try {
            $usage = new IsolatedSessionLog($this->autoload('<?php echo json_encode(["reading" => "read", "input_tokens" => count(getenv()) + count($_ENV), "output_tokens" => 0, "cache_read_input_tokens" => 0, "cache_creation_input_tokens" => 0]); exit(0);'))->usageIn('');
        } finally {
            unset($_ENV['INTAKT_SAMPLE_SECRET']);
            putenv('INTAKT_SAMPLE_SECRET');
        }

        self::assertSame(0, $usage->usage?->inputTokens);
    }

    public function testAReaderThatPassesItsMemoryLimitLeavesTheUsageUnknown(): void
    {
        $usage = new IsolatedSessionLog($this->autoload('<?php $kept = str_repeat("x", 128 * 1024 * 1024); echo "{\"reading\":\"not_reported\"}";'))->usageIn('');

        self::assertSame(UsageReading::ReaderFailed, $usage->reading);
    }

    public function testAReaderThatPassesItsTimeLimitLeavesTheUsageUnknown(): void
    {
        $started = hrtime(true);

        $usage = new IsolatedSessionLog($this->autoload('<?php sleep(30);'))->usageIn('');

        self::assertSame(UsageReading::ReaderFailed, $usage->reading);
        self::assertLessThan(15, (hrtime(true) - $started) / 1e9);
    }

    public function testAReaderAnswerThatIsNoUsageLeavesTheUsageUnknown(): void
    {
        $usage = new IsolatedSessionLog($this->autoload('<?php echo "{\"reading\":\"read\",\"input_tokens\":-1}"; exit(0);'))->usageIn('');

        self::assertSame(UsageReading::ReaderFailed, $usage->reading);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-usage-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    // A stand-in for the autoloader that answers by itself, so the child's limits and environment can be seen from outside.
    private function autoload(string $code): string
    {
        $path = $this->folder.'/autoload.php';
        new Filesystem()->dumpFile($path, $code);

        return $path;
    }
}
