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
        self::assertSame(['/etc/passwd', '../outside/file.php'], new FindingKey()->namedPaths($this->finding(null, 'Reads /etc/passwd and ../outside/file.php.')));
    }

    public function testTheSameTextAndFilesGiveTheSameKeyInAnyOrder(): void
    {
        $finding = $this->finding('src/Thing.php', 'Not restart safe.');

        self::assertSame(
            new FindingKey()->of($finding, ['src/Thing.php' => 'aaa', 'src/Other.php' => 'bbb']),
            new FindingKey()->of($finding, ['src/Other.php' => 'bbb', 'src/Thing.php' => 'aaa']),
        );
    }

    public function testTheKeyIgnoresCaseAndSpacingOfTheText(): void
    {
        self::assertSame(
            new FindingKey()->of($this->finding('src/Thing.php', 'Not restart  safe.'), ['src/Thing.php' => 'aaa']),
            new FindingKey()->of($this->finding('src/Thing.php', "not\nRestart safe."), ['src/Thing.php' => 'aaa']),
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
