<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository {
    // Removes the file between the walk that found it and its stat, a moment no test can time, then stats it for real, so the stat raises its warning; the size is read through stat() because the name filesize() resolves here; it stays defined for every later test in the process, which is harmless because any other file name passes through to a real stat.
    function filesize(string $filename): int|false
    {
        if ('vanishes.txt' === basename($filename)) {
            unlink($filename);
            clearstatcache(true, $filename);
        }

        $stat = stat($filename);

        return false === $stat ? false : $stat['size'];
    }
}

namespace Intakt\Tests\Core\Review\Infrastructure\Repository {
    use ErrorException;
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
        private int $reporting;

        public function testAFileRemovedBeforeItsSizeIsReadIsRefusedAsNotReadableUnderAHandlerThatThrowsWarnings(): void
        {
            $this->checkout->write('vanishes.txt', 'needle');
            $this->throwWarnings();

            $this->expectException(PathNotReadable::class);
            $this->expectExceptionMessageIs('"vanishes.txt" cannot be read.');

            new SearchFiles($this->checkout->checkout)('needle', 'vanishes.txt');
        }

        public function testAFileThatVanishesDuringADirectorySearchIsRefusedAsNotReadable(): void
        {
            $this->checkout->write('src/vanishes.txt', 'needle');
            $this->throwWarnings();

            $this->expectException(PathNotReadable::class);
            $this->expectExceptionMessageIs('"src/vanishes.txt" cannot be read.');

            new SearchFiles($this->checkout->checkout)('needle', 'src');
        }

        protected function setUp(): void
        {
            $this->checkout = new ReviewCheckout();
        }

        protected function tearDown(): void
        {
            restore_error_handler();
            error_reporting($this->reporting);
            $this->checkout->remove();
        }

        // Throws every warning that is not silenced, as the error handler of a debug kernel does, because the refusal must not depend on the handler.
        private function throwWarnings(): void
        {
            // Because PHPUnit lowers the reporting level while a test runs, which would hide the warning from this handler as a silenced one.
            $this->reporting = error_reporting(E_ALL);

            set_error_handler(static function (int $level, string $message): bool {
                if (0 === (error_reporting() & $level)) {
                    return false;
                }

                throw new ErrorException($message, 0, $level);
            });
        }
    }
}
