<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\Repository\PathNotReadable;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\Repository\SearchTextEmpty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathNotReadable::class)]
#[UsesClass(SearchTextEmpty::class)]
final class SearchFilesTest extends TestCase
{
    private ReviewCheckout $checkout;

    public function testAnEmptyTextIsRefusedByName(): void
    {
        $this->expectException(SearchTextEmpty::class);

        new SearchFiles($this->checkout->checkout)('', '.');
    }

    // A pipe, because it is neither a file nor a directory whatever user runs the test, so the directory iterator cannot open it.
    public function testAPathThatIsNeitherAFileNorADirectoryIsRefusedAsNotReadable(): void
    {
        $this->checkout->pipe('queue');

        $this->expectException(PathNotReadable::class);
        $this->expectExceptionMessageIs('"queue" cannot be searched.');

        new SearchFiles($this->checkout->checkout)('total', 'queue');
    }

    public function testATextInAFileIsFound(): void
    {
        self::assertSame('src/Cart.php:2:// total the cart', new SearchFiles($this->checkout->checkout)('total', '.'));
    }

    protected function setUp(): void
    {
        $this->checkout = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->checkout->remove();
    }
}
