<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Domain;

use Intakt\Core\Git\Domain\SourceLine;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceLine::class)]
final class SourceLineTest extends TestCase
{
    private const string SHA1 = '0123456789abcdef0123456789abcdef01234567';

    public function testALineOfItsOwnNamesTheBranchAndTheCommit(): void
    {
        $source = SourceLine::in("Part 2 of the change.\n\nSource: feat/big@".self::SHA1."\nWaits for #12\n");

        self::assertSame(['feat/big', self::SHA1], [$source?->branch, $source?->commit]);
    }

    public function testASha256CommitIsASource(): void
    {
        $commit = str_repeat('ab', 32);

        self::assertSame($commit, SourceLine::in('Source: feat/big@'.$commit)?->commit);
    }

    public function testALineWithAWindowsLineEndingOrTrailingSpaceIsASource(): void
    {
        self::assertSame('feat/big', SourceLine::in('Source: feat/big@'.self::SHA1." \r\nMore text")?->branch);
    }

    public function testABranchThatHoldsAnAtSignKeepsItAndTheCommitIsAfterTheLastOne(): void
    {
        $source = SourceLine::in('Source: feat/a@b@'.self::SHA1);

        self::assertSame(['feat/a@b', self::SHA1], [$source?->branch, $source?->commit]);
    }

    #[DataProvider('prose')]
    public function testProseIsNotASourceLine(string $text): void
    {
        self::assertNull(SourceLine::in($text));
        self::assertFalse(SourceLine::isNamedIn($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prose(): iterable
    {
        yield 'a sentence that mentions one' => ['Add a Source: feat/big@'.self::SHA1.' line to the task.'];
        yield 'words instead of a commit' => ['Source: the earlier work on feat/big'];
        yield 'a quoted line' => ['> Source: feat/big@'.self::SHA1];
        yield 'an indented line' => ['  Source: feat/big@'.self::SHA1];
        yield 'inline code' => ['`Source: feat/big@'.self::SHA1.'`'];
        yield 'a short commit' => ['Source: feat/big@0123456'];
        yield 'a commit in capitals' => ['Source: feat/big@'.strtoupper(self::SHA1)];
        yield 'a commit of the wrong length' => ['Source: feat/big@'.self::SHA1.'89'];
        yield 'another case' => ['source: feat/big@'.self::SHA1];
        yield 'no line at all' => [''];
    }

    public function testATaskWithTwoSourceLinesIsRefused(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('the task names more than one Source line, so the commit to build from is unclear'));

        SourceLine::in('Source: feat/a@'.self::SHA1."\nSource: feat/b@".self::SHA1);
    }

    public function testATaskWithTwoSourceLinesStillNamesASource(): void
    {
        self::assertTrue(SourceLine::isNamedIn('Source: feat/a@'.self::SHA1."\nSource: feat/b@".self::SHA1));
    }
}
