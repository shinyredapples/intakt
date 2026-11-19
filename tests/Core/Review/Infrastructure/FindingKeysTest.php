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
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingKeys::class)]
#[CoversClass(FindingKey::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathNotInCheckout::class)]
#[UsesClass(PathOutsideCheckout::class)]
final class FindingKeysTest extends TestCase
{
    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('src/A.php', "<?php // a\n");
        $this->repository->commitFile('src/B.php', "<?php // b\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->repository->path.'/src/Locked.php')) {
            chmod($this->repository->path.'/src/Locked.php', 0o644);
        }

        $this->repository->remove();
    }

    public function testAFindingNamingAFileGetsAKeyThatChangesWhenTheFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('src/A.php', 'The lock in src/A.php is released too early.');
    }

    public function testTheFileFieldOfAFindingIsAFileItNames(): void
    {
        $finding = new Finding('F1', Severity::P1, 'src/A.php', 3, null, 'The lock is released too early.', FixableBy::Builder, false, null, null);

        $before = $this->keyOf($finding);
        $this->write('src/A.php', "<?php // edited\n");

        self::assertNotNull($before);
        self::assertNotEquals($before, $this->keyOf($finding));
    }

    public function testAFindingNamingNoRegularFileGetsNoKey(): void
    {
        self::assertNull($this->keyOf($this->finding('The lock is released too early.')));
    }

    public function testAFindingNamingAPathThatIsNotInTheCheckoutGetsNoKey(): void
    {
        self::assertNull($this->keyOf($this->finding('Both src/A.php and src/Gone.php release the lock too early.')));
    }

    public function testAFindingNamingADirectoryWithAFileExtensionGetsNoKey(): void
    {
        mkdir($this->repository->path.'/src/Old.php');

        self::assertNull($this->keyOf($this->finding('src/A.php and src/Old.php release the lock too early.')));
    }

    public function testAFindingNamingAFileOfTheCheckoutThatCannotBeReadGetsNoKey(): void
    {
        $this->write('src/Locked.php', "<?php // locked\n");
        chmod($this->repository->path.'/src/Locked.php', 0o000);

        self::assertNull($this->keyOf($this->finding('src/A.php and src/Locked.php release the lock too early.')));
    }

    public function testABareFileNameOfTheCheckoutThatCannotBeReadGetsNoKey(): void
    {
        $this->write('Locked.php', "<?php // locked\n");
        chmod($this->repository->path.'/Locked.php', 0o000);

        try {
            self::assertNull($this->keyOf($this->finding('src/A.php and Locked.php release the lock too early.')));
        } finally {
            chmod($this->repository->path.'/Locked.php', 0o644);
        }
    }

    public function testAFindingInARemovedCheckoutGetsNoKey(): void
    {
        $finding = $this->finding('The lock in src/A.php is released too early.');
        $root = $this->repository->path;
        $this->repository->remove();

        self::assertNull(new FindingKeys()->of($finding, $root));
        $this->repository = new TemporaryRepository();
    }

    public function testAPathOutsideTheCheckoutGivesNoKeyAndIsNeverRead(): void
    {
        file_put_contents(dirname($this->repository->path).'/outside.php', 'secret');

        try {
            self::assertNull($this->keyOf($this->finding('src/A.php reads ../outside.php too early.')));
        } finally {
            unlink(dirname($this->repository->path).'/outside.php');
        }
    }

    public function testABackquotedPathWithASpaceChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('docs/Release Notes.md', 'The step in `docs/Release Notes.md` is out of date.');
    }

    public function testABackquotedPathWithASpaceAndALineNumberChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('docs/Release Notes.md', 'The step in `docs/Release Notes.md:12` is out of date.');
    }

    public function testABackquotedNonAsciiPathChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('docs/Überblick.md', 'The step in `docs/Überblick.md` is out of date.');
    }

    public function testANonAsciiPathInPlainTextChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('docs/Überblick.md', 'The step in docs/Überblick.md is out of date.');
    }

    public function testANonAsciiFileNameWithoutADirectoryChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('Überblick.md', 'The step in Überblick.md is out of date.');
    }

    public function testABackquotedNameWithCharactersAPlainWordDoesNotHoldChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('docs/Q&A (draft), v2.md', 'The step in `docs/Q&A (draft), v2.md` is out of date.');
    }

    public function testAPlainTextPathWithASpaceInItsDirectoryGetsNoKey(): void
    {
        $this->write('docs/My Notes/Rule.md', "# Rule\n");

        self::assertNull($this->keyOf($this->finding('src/A.php breaks docs/My Notes/Rule.md.')));
    }

    public function testAPlainTextPathWithASpaceInItsFileNameGetsNoKey(): void
    {
        $this->write('docs/Release Notes.md', "# Notes\n");

        self::assertNull($this->keyOf($this->finding('src/A.php breaks docs/Release Notes.md.')));
    }

    public function testAWordWithASlashThatNamesNoPlaceOfTheCheckoutIsNotAFile(): void
    {
        self::assertNotNull($this->keyOf($this->finding('src/A.php locks and/or waits.')));
    }

    public function testADirectoryOfTheCheckoutNamedWithoutAFileExtensionIsNotAFile(): void
    {
        self::assertNotNull($this->keyOf($this->finding('src/A.php is the only lock in src/ and docs.')));
    }

    public function testATrailingLineNumberOrFullStopDoesNotChangeWhichFileIsKeyed(): void
    {
        $plain = $this->keyOf($this->finding('Released early: src/A.php'));

        $this->write('src/A.php', "<?php // edited\n");

        self::assertNotEquals($plain, $this->keyOf($this->finding('Released early: src/A.php')));
        self::assertNotNull($this->keyOf($this->finding('Released early: (src/A.php:12).')));
    }

    public function testTwoFindingsWithTheSameTextNamingDifferentFilesGetDifferentKeys(): void
    {
        $this->write('src/C.php', "<?php // a\n");

        self::assertNotEquals($this->keyOf($this->finding('Released early in src/A.php.')), $this->keyOf($this->finding('Released early in src/C.php.')));
    }

    public function testTwoFindingsNamingTheSameFilesWithDifferentTextGetDifferentKeys(): void
    {
        self::assertNotEquals($this->keyOf($this->finding('Released early in src/A.php.')), $this->keyOf($this->finding('Released late in src/A.php.')));
    }

    public function testTheSameFindingOnUnchangedFilesGetsTheSameKey(): void
    {
        self::assertEquals($this->keyOf($this->finding('src/B.php and `src/A.php` release early.')), $this->keyOf($this->finding('src/B.php and `src/A.php` release early.')));
    }

    public function testEachNamedFileChangesTheKeyWhenItIsEdited(): void
    {
        $before = $this->keyOf($this->finding('src/A.php and src/B.php release early.'));

        $this->write('src/B.php', "<?php // edited\n");

        self::assertNotEquals($before, $this->keyOf($this->finding('src/A.php and src/B.php release early.')));
    }

    public function testReflowedTextKeepsItsKey(): void
    {
        self::assertEquals($this->keyOf($this->finding("Released early\n  in src/A.php.")), $this->keyOf($this->finding('Released early in src/A.php.')));
    }

    public function testSpacingInsideBackquotesOrDoubleQuotesChangesTheKey(): void
    {
        self::assertNotEquals($this->keyOf($this->finding('src/A.php prints `Admin User`.')), $this->keyOf($this->finding('src/A.php prints `Admin  User`.')));
        self::assertNotEquals($this->keyOf($this->finding('src/A.php prints "Admin User".')), $this->keyOf($this->finding('src/A.php prints "Admin  User".')));
    }

    public function testSpacingInsideSingleQuotesKeepsTheKey(): void
    {
        self::assertEquals($this->keyOf($this->finding("src/A.php prints 'Admin User'.")), $this->keyOf($this->finding("src/A.php prints 'Admin  User'.")));
    }

    private function assertKeyFollowsEditsOf(string $path, string $text): void
    {
        $this->write($path, "first\n");
        $before = $this->keyOf($this->finding($text));

        $this->write($path, "second\n");
        $after = $this->keyOf($this->finding($text));

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotEquals($before, $after);
    }

    private function write(string $path, string $content): void
    {
        $full = $this->repository->path.'/'.$path;

        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), recursive: true);
        }

        file_put_contents($full, $content);
    }

    private function keyOf(Finding $finding): ?FindingKey
    {
        return new FindingKeys()->of($finding, $this->repository->path);
    }

    private function finding(string $text): Finding
    {
        return new Finding('F1', Severity::P1, null, null, null, $text, FixableBy::Builder, false, null, null);
    }
}
