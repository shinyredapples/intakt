<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\OutputInParts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(OutputInParts::class)]
final class OutputInPartsTest extends TestCase
{
    private const int PRINTED = 4 * 1024 * 1024;

    public function testAFastWriterIsReadInPartsOfAtMostTheFixedSize(): void
    {
        $longest = 0;
        $bytes = 0;

        foreach ($this->php('echo str_repeat("a", '.self::PRINTED.');')->read() as $part) {
            $longest = max($longest, strlen($part));
            $bytes += strlen($part);
        }

        self::assertSame(self::PRINTED, $bytes);
        self::assertLessThanOrEqual(65_536, $longest);
    }

    public function testAReaderThatStopsEarlyEndsTheProcess(): void
    {
        $parts = $this->php('echo getmypid(), "\n"; flush(); sleep(60);')->read();
        $process = intval($parts->current());
        self::assertGreaterThan(0, $process);

        unset($parts);

        self::assertDirectoryDoesNotExist('/proc/'.$process);
    }

    public function testAnEmptyVariableReachesTheCommandAndAFalseOneDoesNot(): void
    {
        $output = new OutputInParts([PHP_BINARY, '-r', 'var_dump(getenv("EMPTY"), getenv("PATH"));'], __DIR__, ['EMPTY' => '', 'PATH' => false], 60);

        self::assertSame("string(0) \"\"\nbool(false)\n", implode('', iterator_to_array($output->read(), false)));
    }

    public function testAFailingCommandIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        iterator_to_array($this->php('echo "a"; exit(3);')->read());
    }

    public function testAMissingCommandIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        iterator_to_array(new OutputInParts(['/nonexistent/command'], __DIR__, [], 60)->read());
    }

    public function testACommandThatRunsTooLongIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        iterator_to_array($this->php('sleep(60);', 1)->read());
    }

    private function php(string $code, int $seconds = 60): OutputInParts
    {
        return new OutputInParts([PHP_BINARY, '-r', $code], __DIR__, [], $seconds);
    }
}
