<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\FindingKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingKey::class)]
#[UsesClass(Finding::class)]
final class FindingKeyTest extends TestCase
{
    public function testAFindingNamesItsFileAndEveryPathItsTextNames(): void
    {
        $finding = $this->finding('src/Thing.php', 'The call in `src/Other.php:12` and config/app.yaml. skip the lock of src/Thing.php.');

        self::assertSame(['src/Thing.php', 'src/Other.php', 'config/app.yaml'], new FindingKey()->namedPaths($finding));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textsNamingNoPath(): iterable
    {
        yield 'a static call' => ['`Money::from()` rounds wrongly.'];
        yield 'a url' => ['See https://example.com/docs for the rule.'];
        yield 'plain words' => ['The save is not restart safe.'];
    }

    #[DataProvider('textsNamingNoPath')]
    public function testTextWithoutAPathNamesNoFile(string $text): void
    {
        self::assertSame([], new FindingKey()->namedPaths($this->finding(null, $text)));
    }

    public function testAnAbsolutePathOrOneLeavingTheCheckoutIsNamedSoItCanBeRefused(): void
    {
        self::assertSame(['/etc/passwd.conf', '../outside/file.php'], new FindingKey()->namedPaths($this->finding(null, 'Reads /etc/passwd.conf and ../outside/file.php.')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fragmentsThatMayBeAPath(): iterable
    {
        yield 'two words' => ['The lock and/or the save is lost.', 'and/or'];
        yield 'a pair of modes' => ['The read/write lock is missing.', 'read/write'];
        yield 'two versions' => ['Fails on PHP 8.5/8.6.', '8.5/8.6'];
        yield 'a directory' => ['Everything in src/Core is affected.', 'src/Core'];
        yield 'a route' => ['The page /reviews fails.', '/reviews'];
        yield 'a file name without a directory' => ['README.md still states the old limit.', 'README.md'];
        yield 'an abbreviation' => ['Some writes, e.g. the save, are lost.', 'e.g'];
        yield 'a file name without a directory or an extension' => ['The target in `Makefile` skips the lock.', 'Makefile'];
    }

    #[DataProvider('fragmentsThatMayBeAPath')]
    public function testAFragmentThatReadsAsProseAsWellIsAPathOnlyWhenTheCheckoutHasIt(string $text, string $fragment): void
    {
        $finding = $this->finding('src/Thing.php', $text);

        self::assertSame(['src/Thing.php'], new FindingKey()->namedPaths($finding));
        self::assertContains($fragment, new FindingKey()->possiblePaths($finding));
    }

    public function testEveryWordOfTheTextMayBeAPath(): void
    {
        self::assertSame(['The', 'target', 'in', 'Makefile', 'and/or', 'skips', 'the', 'lock'], new FindingKey()->possiblePaths($this->finding('src/Thing.php', 'The target in `Makefile` and/or src/Other.php skips the lock.')));
    }

    public function testAPathTheFindingCertainlyNamesIsNoPossiblePath(): void
    {
        self::assertSame(['Breaks', 'and'], new FindingKey()->possiblePaths($this->finding('src/Thing.php', 'Breaks src/Thing.php and src/Other.php.')));
    }

    public function testTheSameTextAndFilesGiveTheSameKeyInAnyOrder(): void
    {
        $finding = $this->finding('src/Thing.php', 'Not restart safe.');

        self::assertSame(
            new FindingKey()->of($finding, ['src/Thing.php' => 'aaa', 'src/Other.php' => 'bbb']),
            new FindingKey()->of($finding, ['src/Other.php' => 'bbb', 'src/Thing.php' => 'aaa']),
        );
    }

    public function testTheKeyIgnoresSpacingOfTheText(): void
    {
        self::assertSame(
            new FindingKey()->of($this->finding('src/Thing.php', 'Not restart  safe.'), ['src/Thing.php' => 'aaa']),
            new FindingKey()->of($this->finding('src/Thing.php', " Not\nrestart safe."), ['src/Thing.php' => 'aaa']),
        );
    }

    public function testTheKeyKeepsTheCaseOfTheText(): void
    {
        self::assertNotSame(
            new FindingKey()->of($this->finding('src/Thing.php', 'The role `Admin` is never checked.'), ['src/Thing.php' => 'aaa']),
            new FindingKey()->of($this->finding('src/Thing.php', 'The role `admin` is never checked.'), ['src/Thing.php' => 'aaa']),
        );
    }

    public function testTheKeyChangesWhenTheFindingTextChanges(): void
    {
        self::assertNotSame(
            new FindingKey()->of($this->finding('src/Thing.php', 'Not restart safe.'), ['src/Thing.php' => 'aaa']),
            new FindingKey()->of($this->finding('src/Thing.php', 'Leaks the token.'), ['src/Thing.php' => 'aaa']),
        );
    }

    public function testTheKeyChangesWhenANamedFilesContentChanges(): void
    {
        $finding = $this->finding('src/Thing.php', 'Not restart safe.');

        self::assertNotSame(new FindingKey()->of($finding, ['src/Thing.php' => 'aaa']), new FindingKey()->of($finding, ['src/Thing.php' => 'bbb']));
    }

    public function testAPathAndHashCannotBeShiftedIntoAnotherOfTheSameCharacters(): void
    {
        $finding = $this->finding('src/Thing.php', 'Not restart safe.');

        self::assertNotSame(new FindingKey()->of($finding, ['a' => 'bc']), new FindingKey()->of($finding, ['ab' => 'c']));
    }

    public function testAFindingNamingNoFileGetsNoKey(): void
    {
        self::assertNull(new FindingKey()->of($this->finding(null, 'Not restart safe.'), []));
    }

    public function testTheKeyIsASha256(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) new FindingKey()->of($this->finding('src/Thing.php', 'Not restart safe.'), ['src/Thing.php' => 'aaa']));
    }

    private function finding(?string $file, string $text): Finding
    {
        return new Finding('F1', Severity::P1, $file, null === $file ? null : 3, null, $text, FixableBy::Builder, false, null, null);
    }
}
