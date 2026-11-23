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
use Intakt\Core\Review\Infrastructure\CheckoutFileNames;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Core\Review\Infrastructure\NamesCutAtASpace;
use Intakt\Core\Review\Infrastructure\NamesOutsideCitations;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingKeys::class)]
#[CoversClass(FindingKey::class)]
#[CoversClass(CheckoutFileNames::class)]
#[CoversClass(NamesCutAtASpace::class)]
#[CoversClass(NamesOutsideCitations::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathNotInCheckout::class)]
#[UsesClass(PathOutsideCheckout::class)]
final class FindingKeysTest extends TestCase
{
    private TemporaryRepository $repository;

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

    public function testALinkToAFileWithADigitsOnlyNameChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->write('123', "first\n");
        mkdir($this->repository->path.'/docs');
        symlink('../123', $this->repository->path.'/docs/Link.md');

        $this->assertKeyFollowsEditsOf('docs/Link.md', 'The step in docs/Link.md is out of date.');
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

    public function testABackquotedFileOfTheRootWithoutAFileExtensionChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('Makefile', 'See `Makefile` and src/A.php.');
    }

    public function testAFileFieldWithoutAFileExtensionChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->write('Makefile', "first\n");
        $finding = $this->findingAt('Makefile', 'The target in src/A.php is out of date.');
        $before = $this->keyOf($finding);

        $this->write('Makefile', "second\n");

        self::assertNotNull($before);
        self::assertNotEquals($before, $this->keyOf($finding));
    }

    public function testTheFileFieldIsTakenAsWrittenWithoutCuttingALineNumber(): void
    {
        $this->write('docs/Release.md', "other\n");
        $this->write('docs/Release.md:12', "first\n");
        $finding = $this->findingAt('docs/Release.md:12', 'See src/A.php and the cited file.');
        $before = $this->keyOf($finding);

        $this->write('docs/Release.md:12', "second\n");

        self::assertNotNull($before);
        self::assertNotEquals($before, $this->keyOf($finding));
    }

    public function testAFileFieldThatNamesNoRegularFileGetsNoKey(): void
    {
        self::assertNull($this->keyOf($this->findingAt('src/A.php:12', 'The lock in src/B.php is released too early.')));
    }

    public function testAPlainTextPathWithASpaceAfterADirectoryOfTheCheckoutGetsNoKey(): void
    {
        $this->write('docs/Release/Index.md', "other\n");
        $this->write('docs/Release Notes.md', "# Notes\n");

        self::assertNull($this->keyOf($this->finding('See docs/Release Notes.md and src/A.php.')));
    }

    public function testAPlainTextPathWithASpaceAfterAFileOfTheCheckoutGetsNoKey(): void
    {
        $this->write('docs/Release', "other\n");
        $this->write('docs/Release Notes.md', "# Notes\n");

        self::assertNull($this->keyOf($this->finding('See docs/Release Notes.md and src/A.php.')));
    }

    public function testAPlainTextNameWithASpaceAfterTheDigitZeroGetsNoKey(): void
    {
        $this->write('0 Notes.md', "# Notes\n");

        self::assertNull($this->keyOf($this->finding('See 0 Notes.md and src/A.php.')));
    }

    public function testABareFileNameWithALineNumberChangesTheKeyWhenTheOneFileWithThatNameIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('src/Deep/C.php', 'src/A.php races with `C.php:12`.');
    }

    public function testABareFileNameInPlainTextChangesTheKeyWhenTheOneFileWithThatNameIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('src/Deep/C.php', 'src/A.php races with C.php:12.');
    }

    public function testABareFileNameThatSeveralFilesCarryGetsNoKey(): void
    {
        $this->write('src/Deep/B.php', "<?php // deep b\n");

        self::assertNull($this->keyOf($this->finding('src/A.php races with `B.php:12`.')));
    }

    public function testABareNameWithAFileExtensionThatNoFileCarriesIsNotAFile(): void
    {
        self::assertNotNull($this->keyOf($this->finding('On PHP 8.5, e.g. in src/A.php, the lock is released too early.')));
    }

    public function testABackslashPathChangesTheKeyWhenThatFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('src/Deep/C.php', 'src/A.php races with `src\Deep\C.php`.');
    }

    public function testABackquoteInsideADoubleBackquoteSpanStaysPartOfThePath(): void
    {
        $this->write('docs/Release/Index.md', "other\n");

        $this->assertKeyFollowsEditsOf('docs/Release`Notes.md', 'See ``docs/Release`Notes.md`` and src/A.php.');
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

    #[DataProvider('citationsOfAFileInAFormTheKeyCannotRead')]
    public function testAPathThatNamesNothingInADirectoryOfTheCheckoutGetsNoKey(string $text): void
    {
        self::assertNull($this->keyOf($this->finding($text)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function citationsOfAFileInAFormTheKeyCannotRead(): iterable
    {
        yield 'a method in backticks' => ['See `src/B.php::run()` and src/A.php.'];
        yield 'a method in plain text' => ['See src/B.php::run() and src/A.php.'];
        yield 'a line anchor' => ['See src/B.php#L12 and src/A.php.'];
        yield 'a span with words after the path' => ['See `src/B.php line 12` and src/A.php.'];
        yield 'a line in parentheses' => ['See src/B.php(12) and src/A.php.'];
        yield 'a line with a letter' => ['See src/B.php:L12 and src/A.php.'];
        yield 'a word left by an unclosed backtick' => ['See `docs/x and `src/B.php` and src/A.php.'];
        yield 'a name without a file extension' => ['See src/Gone and src/A.php.'];
        yield 'a path below a file' => ['See src/B.php/run and src/A.php.'];
    }

    public function testAPathWithALineNumberNextToAnotherFileStillChangesTheKeyWhenItsFileIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('src/B.php', 'See `src/B.php:12` and src/A.php.');
    }

    public function testAPathBelowALinkOutOfTheCheckoutGetsNoKey(): void
    {
        symlink(dirname($this->repository->path), $this->repository->path.'/out');

        self::assertNull($this->keyOf($this->finding('See out/nothing and src/A.php.')));
    }

    public function testABareNameWithASpaceInPlainTextGetsNoKeyWhereverItsFileIs(): void
    {
        $this->write('docs/Release Notes.md', "# Notes\n");

        self::assertNull($this->keyOf($this->finding('src/A.php is wrong, see Release Notes.md:3.')));
        self::assertNull($this->keyOf($this->finding('See Release Notes.md and src/A.php.')));
    }

    public function testABareWordThatStartsADirectoryNameWithASpaceGetsNoKeyWhereverThatDirectoryIs(): void
    {
        $this->write('docs/My Notes/Rule.md', "# Rule\n");

        self::assertNull($this->keyOf($this->finding('src/A.php breaks My Notes/Rule.md.')));
    }

    public function testABareWordThatStartsNoNameWithASpaceKeepsTheKey(): void
    {
        $this->write('docs/Release Notes.md', "# Notes\n");

        self::assertNotNull($this->keyOf($this->finding('src/A.php is wrong in the notes.')));
    }

    public function testABareNameWithoutAFileExtensionChangesTheKeyWhenTheOneFileWithThatNameIsEdited(): void
    {
        $this->assertKeyFollowsEditsOf('docker/Dockerfile', 'See `Dockerfile` and src/A.php.');
    }

    #[DataProvider('bareCitationsOfAFileInAFormTheKeyCannotRead')]
    public function testABareNameThatStartsWithAFileNameAndGoesOnGetsNoKey(string $text): void
    {
        self::assertNull($this->keyOf($this->finding($text)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bareCitationsOfAFileInAFormTheKeyCannotRead(): iterable
    {
        yield 'a method in backticks' => ['See `B.php::run()` and src/A.php.'];
        yield 'a method in plain text' => ['See B.php::run() and src/A.php.'];
        yield 'a line anchor' => ['See B.php#L12 and src/A.php.'];
        yield 'a span with words after the name' => ['See `B.php line 12` and src/A.php.'];
    }

    #[DataProvider('filesNamedOutsideACitationTheKeyCanRead')]
    public function testAFileNamedOutsideACitationTheKeyCanReadNeverLeavesTheKeyUnchangedWhenThatFileIsEdited(string $path, string $text): void
    {
        $this->write($path, "first\n");
        $before = $this->keyOf($this->finding($text));

        $this->write($path, "second\n");

        self::assertTrue(!$before instanceof FindingKey || $before->value !== $this->keyOf($this->finding($text))?->value, $text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filesNamedOutsideACitationTheKeyCanRead(): iterable
    {
        yield 'a partial path with a method' => ['src/Core/B.php', 'See Core/B.php::run() and src/A.php.'];
        yield 'a partial path of a file without an extension' => ['docker/Infra/Dockerfile', 'See Infra/Dockerfile and src/A.php.'];
        yield 'a name after an equals sign' => ['src/B.php', 'See file=B.php and src/A.php.'];
        yield 'a name after an at sign' => ['src/B.php', 'See @B.php and src/A.php.'];
        yield 'a namespace after a path' => ['src/sub/B.php', 'See src/sub/B.php:Intakt\\Core\\X::run() and src/A.php.'];
        yield 'a method with a slash after a path' => ['src/sub/B.php', 'See src/sub/B.php::Foo/bar() and src/A.php.'];
        yield 'two line numbers with a slash in backticks' => ['src/sub/B.php', 'See `src/sub/B.php:12/14` and src/A.php.'];
        yield 'a namespace after a bare name' => ['src/sub/C.php', 'See C.php:Intakt\\Core\\X and src/A.php.'];
        yield 'a leading slash' => ['src/B.php', 'See /src/B.php and src/A.php.'];
        yield 'a name in another case' => ['src/B.php', 'See b.PHP and src/A.php.'];
        yield 'a partial path that is not the start of the file\'s path' => ['src/B.php', 'See Core/B.php::run() and src/A.php.'];
        yield 'a namespace after the bare name of a nested file' => ['src/sub/B.php', 'src/A.php and B.php:Intakt\\Core\\X break.'];
        yield 'a final sigma for a sigma' => ['src/σ.php', 'See @ς.php and src/A.php.'];
        yield 'a long s for an s' => ['src/Task.php', 'See @Taſk.php and src/A.php.'];
        yield 'a Kelvin sign for a k' => ['src/Task.php', "See @Tas\u{212A}.php and src/A.php."];
    }

    public function testABareWordThatOnlySharesItsFirstLettersWithAFileNameIsNotAFile(): void
    {
        $this->write('bin/check', "#!/bin/sh\n");

        self::assertNotNull($this->keyOf($this->finding('src/A.php is checked twice, and checkpoints are lost.')));
    }

    public function testACheckoutThatCannotBeListedGetsNoKey(): void
    {
        chmod($this->repository->path, 0o000);

        try {
            self::assertNull($this->keyOf($this->finding('See src/A.php and `B.php:3`.')));
            self::assertNull($this->keyOf($this->finding('See src/A.php.')));
        } finally {
            chmod($this->repository->path, 0o755);
        }
    }

    public function testADirectoryOfTheCheckoutThatCannotBeListedGetsNoKey(): void
    {
        mkdir($this->repository->path.'/closed', 0o000);

        try {
            self::assertNull($this->keyOf($this->finding('See src/A.php.')));
        } finally {
            chmod($this->repository->path.'/closed', 0o755);
        }
    }

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

    private function keyOf(Finding $finding): ?FindingKey
    {
        return new FindingKeys()->of($finding, $this->repository->path);
    }

    private function write(string $path, string $content): void
    {
        $full = $this->repository->path.'/'.$path;

        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), recursive: true);
        }

        file_put_contents($full, $content);
    }

    private function finding(string $text): Finding
    {
        return new Finding('F1', Severity::P1, null, null, null, $text, FixableBy::Builder, false, null, null);
    }

    private function findingAt(string $file, string $text): Finding
    {
        return new Finding('F1', Severity::P1, $file, null, null, $text, FixableBy::Builder, false, null, null);
    }
}
