<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository {
    // Stands in for a file removed between the walk that found it and its stat, a moment no test can time; every other file keeps its size, read through stat() because the name filesize() resolves here.
    function filesize(string $filename): int|false
    {
        $stat = 'vanishes.txt' === basename($filename) ? false : stat($filename);

        return false === $stat ? false : $stat['size'];
    }
}

namespace Intakt\Tests\Core\Review\Infrastructure\Repository {
    use Intakt\Core\Review\Contract\Checkout;
    use Intakt\Core\Review\Infrastructure\Repository\PathNotReadable;
    use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\UsesClass;
    use PHPUnit\Framework\TestCase;

    #[CoversClass(SearchFiles::class)]
    #[UsesClass(Checkout::class)]
    #[UsesClass(PathNotReadable::class)]
    final class SearchFilesVanishingFileTest extends TestCase
    {
        private ReviewCheckout $checkout;

        protected function setUp(): void
        {
            $this->checkout = new ReviewCheckout();
        }

        protected function tearDown(): void
        {
            $this->checkout->remove();
        }

        public function testAFileWhoseSizeCannotBeReadIsRefusedAsNotReadable(): void
        {
            $this->checkout->write('vanishes.txt', 'needle');

            $this->expectException(PathNotReadable::class);
            $this->expectExceptionMessageIs('"vanishes.txt" cannot be read.');

            new SearchFiles($this->checkout->checkout)('needle', 'vanishes.txt');
        }

        public function testAFileThatVanishesDuringADirectorySearchIsRefusedAsNotReadable(): void
        {
            $this->checkout->write('src/vanishes.txt', 'needle');

            $this->expectException(PathNotReadable::class);
            $this->expectExceptionMessageIs('"src/vanishes.txt" cannot be read.');

            new SearchFiles($this->checkout->checkout)('needle', 'src');
        }
    }
}
