<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\SearchGroup;
use Intakt\Core\Dashboard\Domain\SearchQuery;
use Intakt\Core\Dashboard\Domain\SearchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchQuery::class)]
#[UsesClass(SearchResult::class)]
final class SearchQueryTest extends TestCase
{
    public function testAQueryOfOnlySpacesIsEmpty(): void
    {
        self::assertTrue(new SearchQuery(" \t ")->isEmpty());
    }

    public function testAQueryKeepsItsTextWithoutTheSurroundingSpaces(): void
    {
        self::assertSame('alpha round', new SearchQuery('  alpha round ')->text);
    }

    public function testAResultMatchesWhenEveryWordIsInItsLabelDetailOrGroup(): void
    {
        $result = new SearchResult(SearchGroup::Projects, 'alpha', 'Project, 2 review rounds');

        self::assertTrue(new SearchQuery('ALPHA projects rounds')->matches($result));
    }

    public function testAResultDoesNotMatchWhenOneWordIsMissing(): void
    {
        $result = new SearchResult(SearchGroup::Projects, 'alpha', 'Project, 2 review rounds');

        self::assertFalse(new SearchQuery('alpha zebra')->matches($result));
    }

    public function testCaseIsIgnoredBeyondAscii(): void
    {
        $result = new SearchResult(SearchGroup::Projects, 'Größe', 'Project, 1 review round');

        self::assertTrue(new SearchQuery('GRÖßE')->matches($result));
    }

    public function testAQueryThatIsNotValidUtf8MatchesNothingInsteadOfFailing(): void
    {
        $query = new SearchQuery("alpha\xff");

        self::assertFalse($query->isEmpty());
        self::assertFalse($query->matches(new SearchResult(SearchGroup::Projects, 'alpha', 'Project, 1 review round')));
    }
}
