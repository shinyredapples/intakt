<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Domain\NearestChangedLines;
use Intakt\Core\Review\Domain\QuotesInChange;
use Intakt\Core\Review\Infrastructure\AddedLinesInCheckout;
use Intakt\Core\Review\Infrastructure\CheckedOutFile;
use Intakt\Core\Review\Infrastructure\DiffAddedLines;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddedLinesInCheckout::class)]
#[CoversClass(CheckedOutFile::class)]
#[UsesClass(DiffAddedLines::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(NearestChangedLines::class)]
#[UsesClass(QuotesInChange::class)]
final class AddedLinesInCheckoutTest extends TestCase
{
    // With a CRLF inside, because a held credential read from a file written on another system may hold one.
    private const string HELD = "held-secret\r\nsecond-half";

    private TemporaryRepository $repository;

    public function testAnAddedLineCarriesItsTextAsItStandsInTheCheckedOutFile(): void
    {
        $this->repository->commitFile('src/A.php', "keep\n\$reason = 1;\n");

        self::assertSame([['src/A.php', 2, '$reason = 1;']], $this->linesOf($this->diffAdding('src/A.php', 2, 1)));
    }

    public function testACarriageReturnIsTakenOffTheRedactedLine(): void
    {
        $this->repository->commitFile('src/A.php', "\$reason = 'other-secret';\r\nkeep\r\n");

        self::assertSame([['src/A.php', 1, "\$reason = '[redacted]';"]], $this->linesOf($this->diffAdding('src/A.php', 1, 1)));
    }

    public function testAPrivateKeyBodyLineWhoseBeginLineIsOutsideTheHunkGivesNoLine(): void
    {
        $dashes = str_repeat('-', 5);
        $this->repository->commitFile('jwt.pem', $dashes.'BEGIN PRIVATE KEY'.$dashes."\nMIIEvQIBADANBgkqhkiG9w0B\nAQEFAASC7signing9Key+x\n".$dashes.'END PRIVATE KEY'.$dashes."\n");

        self::assertSame([], $this->linesOf($this->diffAdding('jwt.pem', 3, 1)));
    }

    public function testAHeldCredentialHoldingACrlfAddedOnTwoLinesLeavesNoPart(): void
    {
        $this->repository->commitFile('src/A.php', "\$signingKey = '".self::HELD."';\r\n");

        self::assertSame([], $this->linesOf($this->diffAdding('src/A.php', 1, 2)));
    }

    public function testAFileMissingFromTheCheckoutGivesNoLineWhileAnotherFileStillDoes(): void
    {
        $this->repository->commitFile('src/B.php', "\$reason = 2;\n");

        self::assertSame([['src/B.php', 1, '$reason = 2;']], $this->linesOf($this->diffAdding('src/Gone.php', 1, 1).$this->diffAdding('src/B.php', 1, 1)));
    }

    public function testAPathOutsideTheCheckoutIsNeverRead(): void
    {
        $outside = dirname($this->repository->path).'/outside-'.basename($this->repository->path).'.php';
        file_put_contents($outside, "\$reason = 1;\n");

        try {
            self::assertSame([], $this->linesOf($this->diffAdding('../'.basename($outside), 1, 1)));
        } finally {
            unlink($outside);
        }
    }

    public function testAnAddedSymlinkGivesNoLineOfTheUnchangedFileItPointsTo(): void
    {
        $this->repository->commitFile('notes.txt', "\$reason = 1;\n");
        symlink('notes.txt', $this->repository->path.'/current.txt');

        self::assertSame([], $this->linesOf($this->diffAdding('current.txt', 1, 1)));
    }

    public function testAFileUnderASymlinkedFolderGivesNoLine(): void
    {
        $this->repository->commitFile('real/A.php', "\$reason = 1;\n");
        symlink('real', $this->repository->path.'/linked');

        self::assertSame([], $this->linesOf($this->diffAdding('linked/A.php', 1, 1)));
    }

    public function testACheckoutThatIsGoneGivesNoLine(): void
    {
        self::assertSame([], new AddedLinesInCheckout(new DiffAddedLines(new DiffPaths()), $this->redactor())->of($this->diffAdding('src/A.php', 1, 1), $this->repository->path.'/gone'));
    }

    public function testALineNumberPastTheFilesEndGivesNoLine(): void
    {
        $this->repository->commitFile('src/A.php', "\$reason = 1;\n");

        self::assertSame([], $this->linesOf($this->diffAdding('src/A.php', 5, 1)));
    }

    public function testALineIsRedactedWholeBeforeTheRuleCutsItSoACredentialAcrossTheCutLeavesNoPart(): void
    {
        $this->repository->commitFile('src/A.php', '$reason = \''.str_repeat('a', NearestChangedLines::MAX_LINE_LENGTH - 16)."other-secret-value';\n");

        $nearest = new NearestChangedLines(static fn (string $text): string => $text)->forQuotesOf('`dropReason` is unused.', $this->linesOf($this->diffAdding('src/A.php', 1, 1)));

        self::assertStringNotContainsString('other', $nearest[0]->lines[0]->text);
        self::assertStringEndsWith('[reda', $nearest[0]->lines[0]->text);
    }

    public function testAHeldCredentialWithBytesThatAreNotUtf8IsRedactedBeforeTheScrub(): void
    {
        $this->repository->commitFile('src/A.php', "\$customer = \"held\xFFbyte\";\n");

        self::assertSame([['src/A.php', 1, '$customer = "[redacted]";']], $this->linesOf($this->diffAdding('src/A.php', 1, 1)));
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function diffAdding(string $path, int $first, int $count): string
    {
        return sprintf("diff --git a/%s b/%s\n@@ -0,0 +%d,%d @@\n", $path, $path, $first, $count).str_repeat("+added\n", $count);
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function linesOf(string $diff): array
    {
        return new AddedLinesInCheckout(new DiffAddedLines(new DiffPaths()), $this->redactor())->of($diff, $this->repository->path);
    }

    private function redactor(): Redactor
    {
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => new Redaction()->redact($text, self::HELD, 'other-secret', 'other-secret-value', "held\xFFbyte"));

        return $redactor;
    }
}
