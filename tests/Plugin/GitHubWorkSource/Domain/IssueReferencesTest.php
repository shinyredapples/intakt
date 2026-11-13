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
        yield 'prose that mentions an issue' => ['This task waits for #9 to land in its own time.', []];
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
