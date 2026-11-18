<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\Repository\EntryNotReadable;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\Repository\SearchTextEmpty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(EntryNotReadable::class)]
#[UsesClass(SearchTextEmpty::class)]
final class SearchFilesTest extends TestCase
{
    private ReviewCheckout $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testAnEmptyTextIsRefusedAsASearchWithoutText(): void
    {
        $this->expectException(SearchTextEmpty::class);

        new SearchFiles($this->fixture->checkout)('', '.');
    }

    public function testAFileThatCannotBeReadIsSkippedAndTheSearchGoesOn(): void
    {
        $this->fixture->write('held/Locked.php', 'cart');
        $this->fixture->write('held/Open.php', 'cart');
        $this->fixture->chmod('held/Locked.php', 0000);

        try {
            self::assertSame('held/Open.php:1:cart', new SearchFiles($this->fixture->checkout)('cart', 'held'));
        } finally {
            $this->fixture->chmod('held/Locked.php', 0644);
        }
    }

    public function testANamedFileThatCannotBeReadIsRefusedAsAnEntryNotReadable(): void
    {
        $this->fixture->write('held/Locked.php', 'cart');
        $this->fixture->chmod('held/Locked.php', 0000);

        try {
            $this->expectExceptionObject(EntryNotReadable::because('held/Locked.php', 'cannot be read'));

            new SearchFiles($this->fixture->checkout)('cart', 'held/Locked.php');
        } finally {
            $this->fixture->chmod('held/Locked.php', 0644);
        }
    }

    public function testADirectoryTheSearchCannotOpenIsRefusedAsAnEntryNotReadable(): void
    {
        $this->fixture->write('locked/Hidden.php', 'cart');
        $this->fixture->chmod('locked', 0000);

        try {
            $this->expectException(EntryNotReadable::class);

            new SearchFiles($this->fixture->checkout)('cart', 'locked');
        } finally {
            $this->fixture->chmod('locked', 0755);
        }
    }
}
