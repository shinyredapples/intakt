<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueuedIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
final class QueuedIssueListingTest extends TestCase
{
    private const string BODY = "Waits for #2 and #3\nCloses #7 and fixes #8";

    /**
     * @param list<string> $labels
     */
    #[DataProvider('edits')]
    public function testOnlyAnEditThatChangesWhatSteersLeavesTheItemNotListedAsPlanned(string $body, array $labels, bool $listedAsPlanned): void
    {
        $references = new IssueReferences();
        $issue = new QueuedIssue(new ItemReading(
            Issues::task(5, ['intakt'], ['body' => self::BODY]),
            $references->waitsFor(self::BODY),
            $references->unreadableWaits(self::BODY),
            $references->closedBy(self::BODY),
        ));

        self::assertSame($listedAsPlanned, $issue->isListedAs(new IssueHistory('ana', [], $body, $labels), $references));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function edits(): iterable
    {
        yield 'a changed wait' => ["Waits for #2 and #4\nCloses #7 and fixes #8", ['intakt'], false];
        yield 'a changed closing target' => ["Waits for #2 and #3\nCloses #7 and fixes #9", ['intakt'], false];
        yield 'a changed label set' => [self::BODY, ['intakt', 'idea'], false];
        yield 'a reworded wait note' => ["Waits for #2 and #3 before starting\nCloses #7 and fixes #8", ['intakt'], true];
        yield 'reordered waits' => ["Waits for #3 and #2\nCloses #7 and fixes #8", ['intakt'], true];
        yield 'reordered closing references' => ["Waits for #2 and #3\nFixes #8 and closes #7", ['intakt'], true];
    }
}
