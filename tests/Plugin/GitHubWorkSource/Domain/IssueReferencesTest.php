<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueReferences::class)]
final class IssueReferencesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function waitLines(): iterable
    {
        yield 'one issue' => ["Do it.\n\nWaits for #9\n", [9]];
        yield 'several on one line' => ['Waits for #9, #10 and #11', [9, 10, 11]];
        yield 'several lines' => ["Waits for #9\nWaits for #4.", [9, 4]];
        yield 'any case, indented' => ['   waits FOR #7', [7]];
        yield 'twice named' => ["Waits for #9\nWaits for #9", [9]];
        yield 'an Oxford comma' => ['Waits for #9, #10, and #11', [9, 10, 11]];
        yield 'a comma and the last one' => ['Waits for #9, and #10', [9, 10]];
        yield 'a note after the number' => ['Waits for #9 (the base)', [9]];
        yield 'what it provides after a colon' => ['Waits for #1600: the forge reads', [1600]];
        yield 'a colon after the words' => ['Waits for: #9', [9]];
        yield 'Windows line ends' => ["Waits for #9\r\nWaits for #10 (the base)\r\n", [9, 10]];
        yield 'in a markdown list' => ["- Waits for #9\n* Waits for #10", [9, 10]];
        yield 'in bold' => ['**Waits for** #9', [9]];
        yield 'in bold with the colon inside' => ['**Waits for:** #9', [9]];
        yield 'in bold with the colon outside' => ['**Waits for**: #9', [9]];
        yield 'in bold with underscores' => ['__Waits for__ #9', [9]];
        yield 'in italics' => ['*Waits for* #9', [9]];
        yield 'in italics with underscores and a colon' => ['_Waits for:_ #9', [9]];
        yield 'in a blockquote' => ['> Waits for #9', [9]];
        yield 'in a nested blockquote' => ['> > Waits for #9', [9]];
        yield 'in a blockquote without a space' => ['>>Waits for #9', [9]];
        yield 'in bold in a list in a blockquote' => ['  > - **Waits for:** #9, #10', [9, 10]];
        yield 'prose that mentions an issue' => ['This task waits for #9 to land in its own time.', []];
        yield 'a bold label before prose that mentions an issue' => ['**Note:** this waits for #9', []];
        yield 'a plain reference' => ['See #9', []];
        yield 'nothing' => ['', []];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('waitLines')]
    public function testOnlyAWaitsForLineNamesTheIssuesATaskWaitsOn(string $body, array $expected): void
    {
        self::assertSame($expected, new IssueReferences()->waitsFor($body));
        self::assertSame([], new IssueReferences()->unreadableWaits($body));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function unreadableWaitLines(): iterable
    {
        yield 'no number' => ["Do it.\nWaits for the forge reads\n", ['Waits for the forge reads']];
        yield 'another repository' => ['Waits for billing#12', ['Waits for billing#12']];
        yield 'a number without its sign' => ['Waits for #9 and 10', ['Waits for #9 and 10']];
        yield 'a bare number in the note' => ['Waits for #9 then 10', ['Waits for #9 then 10']];
        yield 'a digit in the note' => ['Waits for #9 (part 1)', ['Waits for #9 (part 1)']];
        yield 'another number in the note' => ['  - Waits for #9 (after #8)', ['- Waits for #9 (after #8)']];
        yield 'nothing after the words' => ['Waits for', ['Waits for']];
        yield 'in bold, no number' => ['**Waits for** the base', ['**Waits for** the base']];
        yield 'in bold with the colon inside, a bare number' => ['**Waits for:** #9 then 10', ['**Waits for:** #9 then 10']];
        yield 'in bold with underscores, another repository' => ['__Waits for__ billing#12', ['__Waits for__ billing#12']];
        yield 'in italics, a number without its sign' => ['*Waits for* #9 and 10', ['*Waits for* #9 and 10']];
        yield 'in a blockquote, another repository' => ['> Waits for billing#12', ['> Waits for billing#12']];
        yield 'in a nested blockquote, no number' => ['> > Waits for the base', ['> > Waits for the base']];
        yield 'in a list in a blockquote, nothing after the words' => ['> - **Waits for**', ['> - **Waits for**']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('unreadableWaitLines')]
    public function testAWaitsForLineInAnotherFormIsUnreadableInsteadOfIgnored(string $body, array $expected): void
    {
        self::assertSame($expected, new IssueReferences()->unreadableWaits($body));
        self::assertSame([], new IssueReferences()->waitsFor($body));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function closingKeywords(): iterable
    {
        yield 'closes' => ['Closes #329', [329]];
        yield 'fixes in lower case' => ['fixes #12', [12]];
        yield 'resolved' => ['Resolved #5 and more', [5]];
        yield 'two keywords' => ["Closes #1\nFixes #2", [1, 2]];
        yield 'a plain reference' => ['Related to #9', []];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('closingKeywords')]
    public function testAPullRequestClosesTheIssuesItsClosingKeywordsName(string $body, array $expected): void
    {
        self::assertSame($expected, new IssueReferences()->closedBy($body));
    }
}
