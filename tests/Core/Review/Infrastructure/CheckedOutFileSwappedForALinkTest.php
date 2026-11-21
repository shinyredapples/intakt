<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract {
    use Intakt\Tests\Core\Review\Infrastructure\CheckedOutFileSwappedForALinkTest;

    // Swaps the file for a link right after it was resolved, a moment no test can time; it stays defined for every later test in the process, which is harmless because any other path passes through to a real resolve.
    function realpath(string $path): string|false
    {
        $resolved = CheckedOutFileSwappedForALinkTest::resolvedForReal($path);

        if ('resolved-then-swapped.txt' === basename($path) && is_file($path.'.swap')) {
            rename($path.'.swap', $path);
            clearstatcache(true);
        }

        return $resolved;
    }
}

namespace Intakt\Core\Review\Infrastructure {
    use Intakt\Tests\Core\Review\Infrastructure\CheckedOutFileSwappedForALinkTest;

    // Swaps the file for a link between its check and its open, a moment no test can time; it stays defined for every later test in the process, which is harmless because any other file name passes through to a real open.
    /**
     * @return resource|false
     */
    function fopen(string $filename, string $mode)
    {
        if ('checked-then-swapped.txt' === basename($filename) && is_file($filename.'.swap')) {
            rename($filename.'.swap', $filename);
            clearstatcache(true);
        }

        return CheckedOutFileSwappedForALinkTest::openedForReal($filename, $mode);
    }
}

namespace Intakt\Tests\Core\Review\Infrastructure {
    use Intakt\Core\Review\Contract\Checkout;
    use Intakt\Core\Review\Infrastructure\CheckedOutFile;
    use Intakt\Tests\Core\Git\TemporaryRepository;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\UsesClass;
    use PHPUnit\Framework\TestCase;

    #[CoversClass(CheckedOutFile::class)]
    #[UsesClass(Checkout::class)]
    final class CheckedOutFileSwappedForALinkTest extends TestCase
    {
        private const string PRIVATE_TEXT = "unchanged private text\n";

        private TemporaryRepository $repository;

        protected function setUp(): void
        {
            $this->repository = new TemporaryRepository();
            file_put_contents($this->repository->path.'/untouched.txt', self::PRIVATE_TEXT);
        }

        protected function tearDown(): void
        {
            $this->repository->remove();
        }

        public function testAFileSwappedForALinkAfterItWasResolvedGivesNoTextOfTheLinksTarget(): void
        {
            $this->prepareSwap('resolved-then-swapped.txt');

            self::assertNull(CheckedOutFile::contentBehindNoLinkOf($this->checkout(), 'resolved-then-swapped.txt'));
        }

        public function testAFileSwappedForALinkBetweenItsCheckAndItsOpenGivesNoTextOfTheLinksTarget(): void
        {
            $this->prepareSwap('checked-then-swapped.txt');

            self::assertNull(CheckedOutFile::contentBehindNoLinkOf($this->checkout(), 'checked-then-swapped.txt'));
        }

        public function testTheQuoteCheckReadsNoTextOfALinkSwappedInBetweenItsCheckAndItsOpen(): void
        {
            $this->prepareSwap('checked-then-swapped.txt');

            self::assertNull(CheckedOutFile::contentOf($this->checkout(), 'checked-then-swapped.txt'));
        }

        public function testAFolderAtTheResolvedPathGivesNoText(): void
        {
            mkdir($this->repository->path.'/folder');

            self::assertNull(CheckedOutFile::contentBehindNoLinkOf($this->checkout(), 'folder'));
        }

        public function testARegularFileThatStaysInPlaceIsReadWhole(): void
        {
            file_put_contents($this->repository->path.'/kept.txt', "first\r\nsecond\n");

            self::assertSame("first\r\nsecond\n", CheckedOutFile::contentBehindNoLinkOf($this->checkout(), 'kept.txt'));
        }

        // Called from the overrides, because only a call outside their namespace reaches the real function.
        public static function resolvedForReal(string $path): string|false
        {
            return realpath($path);
        }

        /**
         * @return resource|false
         */
        public static function openedForReal(string $filename, string $mode)
        {
            return fopen($filename, $mode);
        }

        private function prepareSwap(string $name): void
        {
            file_put_contents($this->repository->path.'/'.$name, "changed line\n");
            symlink('untouched.txt', $this->repository->path.'/'.$name.'.swap');
        }

        private function checkout(): Checkout
        {
            return new Checkout($this->repository->path);
        }
    }
}
