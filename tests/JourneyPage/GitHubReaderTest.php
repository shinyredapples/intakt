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
        yield 'an unknown state' => ['[[{"state": "weird", "milestone": null}]]'];
        yield 'a closed issue without a moment' => ['[[{"state": "closed", "closed_at": null, "milestone": null}]]'];
        yield 'a moment that is not a date' => ['[[{"state": "closed", "closed_at": "never", "milestone": null}]]'];
        yield 'a milestone without a number' => ['[[{"state": "open", "milestone": {"title": "x"}}]]'];
    }
}
