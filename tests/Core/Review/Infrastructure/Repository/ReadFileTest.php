<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Plugin\Forge\Contract\FileContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadFile::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(FileContent::class)]
final class ReadFileTest extends TestCase
{
    private Opens $opens;

    public function testAFileWithANulByteAtTheStartIsNamedBinaryAfterOneOpen(): void
    {
        $answer = new ReadFile(new Checkout(__DIR__))->contentAt(CountedBytes::EARLY_NUL, 'early.bin');

        self::assertSame(FileContent::binaryText(CountedBytes::SIZE), $answer);
        self::assertSame(1, $this->opens->total());
    }

    public function testAFileWhoseOnlyNulByteLiesPastTheFirstReadIsStillNamedBinaryAfterTwoOpens(): void
    {
        $answer = new ReadFile(new Checkout(__DIR__))->contentAt(CountedBytes::LATE_NUL, 'late.bin');

        self::assertSame(FileContent::binaryText(CountedBytes::SIZE), $answer);
        self::assertSame(2, $this->opens->total());
    }

    protected function setUp(): void
    {
        $this->opens = new Opens();
        stream_wrapper_register(CountedBytes::PROTOCOL, CountedBytes::class);
        stream_context_set_default([CountedBytes::PROTOCOL => ['opens' => $this->opens]]);
    }

    protected function tearDown(): void
    {
        // PHP cannot remove a default-context option, so the counter is nulled: an empty entry for our own protocol stays.
        stream_context_set_default([CountedBytes::PROTOCOL => ['opens' => null]]);

        if (in_array(CountedBytes::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(CountedBytes::PROTOCOL);
        }
    }
}
