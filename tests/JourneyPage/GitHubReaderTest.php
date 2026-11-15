<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubReader::class)]
#[CoversClass(RecordedApi::class)]
#[CoversClass(JourneyResource::class)]
#[UsesClass(Issue::class)]
#[UsesClass(Milestone::class)]
#[UsesClass(PullRequest::class)]
#[UsesClass(JourneyInputFailed::class)]
final class GitHubReaderTest extends TestCase
{
    public function testPullRequestsAreLeftOutOfTheIssues(): void
    {
        $issues = new GitHubReader(new RecordedApi(__DIR__.'/Fixtures/api'))->issues();

        self::assertCount(7, $issues);
    }

    public function testAnIssueClosedAsADuplicateCountsAsDroppedNotDone(): void
    {
        $api = self::createStub(GitHubApi::class);
        $api->method('pages')->willReturn('[[{"state": "closed", "created_at": "2026-02-26T00:00:00Z", "closed_at": "2026-02-27T00:00:00Z", "state_reason": "duplicate", "milestone": null}]]');

        $issues = new GitHubReader($api)->issues();

        self::assertTrue($issues[0]->dropped);
        self::assertFalse($issues[0]->isDone());
    }

    public function testAnIssueKeepsTheMomentItWasCreated(): void
    {
        $issues = new GitHubReader(new RecordedApi(__DIR__.'/Fixtures/api'))->issues();

        self::assertSame('2026-10-27T09:00:00+00:00', $issues[0]->created->format(DATE_ATOM));
    }

    public function testAMissingRecordedAnswerIsRefused(): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/'.preg_quote('no recorded answer milestones.json', '/').'/');

        new GitHubReader(new RecordedApi(__DIR__.'/Fixtures/none'))->milestones();
    }

    #[DataProvider('malformedAnswers')]
    public function testAnAnswerThatIsNotTheExpectedShapeIsRefused(string $json): void
    {
        $api = self::createStub(GitHubApi::class);
        $api->method('pages')->willReturn($json);

        $this->expectException(JourneyInputFailed::class);

        new GitHubReader($api)->issues();
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function malformedAnswers(): Iterator
    {
        yield 'not json' => ['<html>'];
        yield 'an object' => ['{"message": "Bad credentials"}'];
        yield 'a page that is not a list' => ['[{"message": "x"}]'];
        yield 'an item that is not an object' => ['[[1]]'];
        yield 'an unknown state' => ['[[{"state": "weird", "created_at": "2026-02-26T00:00:00Z", "milestone": null}]]'];
        yield 'a closed issue without a moment' => ['[[{"state": "closed", "created_at": "2026-02-26T00:00:00Z", "closed_at": null, "milestone": null}]]'];
        yield 'a moment that is not a date' => ['[[{"state": "closed", "created_at": "2026-02-26T00:00:00Z", "closed_at": "never", "milestone": null}]]'];
        yield 'a date that does not exist' => ['[[{"state": "closed", "created_at": "2026-02-26T00:00:00Z", "closed_at": "2026-02-30T00:00:00Z", "milestone": null}]]'];
        yield 'a milestone without a number' => ['[[{"state": "open", "created_at": "2026-02-26T00:00:00Z", "milestone": {"title": "x"}}]]'];
        yield 'an issue without a created moment' => ['[[{"state": "open", "milestone": null}]]'];
        yield 'a created moment that is not a date' => ['[[{"state": "open", "created_at": "soon", "milestone": null}]]'];
    }
}
