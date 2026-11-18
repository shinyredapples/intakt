<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\FindingKey;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingKeys::class)]
#[UsesClass(FindingKey::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathNotInCheckout::class)]
#[UsesClass(PathOutsideCheckout::class)]
final class FindingKeysTest extends TestCase
{
    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('src/Thing.php', "<?php\nfinal class Thing {}\n");
        $this->repository->commitFile('src/Other.php', "<?php\nfinal class Other {}\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testAFindingNamingRegularFilesOfTheCheckoutGetsAKey(): void
    {
        self::assertNotNull($this->keyOf($this->finding('src/Thing.php', 'Calls src/Other.php without the lock.')));
    }

    public function testTheKeyIsTheSameWhileTheNamedFilesAreUnchanged(): void
    {
        $finding = $this->finding('src/Thing.php', 'Not restart safe.');
        $before = $this->keyOf($finding);
        $this->repository->commitFile('src/Unnamed.php', "<?php\n");

        self::assertSame($before, $this->keyOf($finding));
    }

    public function testTheKeyChangesWhenANamedFileChangesAtTheHead(): void
    {
        $finding = $this->finding('src/Thing.php', 'Calls src/Other.php without the lock.');
        $before = $this->keyOf($finding);
        $this->repository->commitFile('src/Other.php', "<?php\nfinal class Other { public function lock() {} }\n");

        self::assertNotSame($before, $this->keyOf($finding));
    }

    public function testAFindingNamingNoFileGetsNoKey(): void
    {
        self::assertNull($this->keyOf($this->finding(null, 'The save is not restart safe.')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNoRegularFile(): iterable
    {
        yield 'a missing file' => ['src/Gone.php'];
        yield 'a directory' => ['src'];
        yield 'a path leaving the checkout' => ['../outside.php'];
        yield 'an absolute path' => ['/etc/hostname'];
        yield 'the git directory' => ['.git/config'];
    }

    #[DataProvider('pathsThatAreNoRegularFile')]
    public function testAFindingNamingAPathThatIsNoRegularFileGetsNoKey(string $path): void
    {
        self::assertNull($this->keyOf($this->finding($path, 'Not restart safe.')));
    }

    public function testAFindingWhoseTextNamesAMissingFileBesideARealOneGetsNoKey(): void
    {
        self::assertNull($this->keyOf($this->finding('src/Thing.php', 'Breaks src/Gone.php too.')));
    }

    public function testASymlinkToAFileOutsideTheCheckoutGetsNoKey(): void
    {
        $outside = dirname($this->repository->path).'/outside-'.basename($this->repository->path).'.php';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->repository->path.'/src/Link.php');

        try {
            self::assertNull($this->keyOf($this->finding('src/Link.php', 'Not restart safe.')));
        } finally {
            unlink($outside);
        }
    }

    public function testAnUnreadableFileGetsNoKey(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The superuser reads every file.');
        }

        chmod($this->repository->path.'/src/Thing.php', 0o000);

        try {
            self::assertNull($this->keyOf($this->finding('src/Thing.php', 'Not restart safe.')));
        } finally {
            chmod($this->repository->path.'/src/Thing.php', 0o644);
        }
    }

    public function testARemovedCheckoutGivesNoKey(): void
    {
        self::assertNull(new FindingKeys()->keyOf($this->finding('src/Thing.php', 'Not restart safe.'), $this->repository->path.'/gone'));
    }

    private function keyOf(Finding $finding): ?string
    {
        return new FindingKeys()->keyOf($finding, $this->repository->path);
    }

    private function finding(?string $file, string $text): Finding
    {
        return new Finding('F1', Severity::P1, $file, null === $file ? null : 3, null, $text, FixableBy::Builder, false, null, null);
    }
}
