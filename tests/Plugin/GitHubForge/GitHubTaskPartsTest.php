<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskParts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubTaskParts::class)]
#[UsesClass(PartIssue::class)]
#[UsesClass(IssueLabels::class)]
final class GitHubTaskPartsTest extends TestCase
{
    private const string MARKER = '<!-- intakt:part task-sizing-abc 1 -->';

    public function testAPartIsFiledWithItsTitleBodyMarkerAndTheOriginalsLabels(): void
    {
        $tracker = new ScriptedIssueTracker();

        $number = $this->fileOnce($tracker);

        self::assertSame(1, $number);
        $filed = $tracker->filed()[0];
        self::assertSame(['Read the cart', "Part 1 of 2 of #7.\n\n".self::MARKER, [['name' => 'ready'], ['name' => 'priority:high']]], [$filed['title'], $filed['body'], $filed['labels']]);
        self::assertContains('POST https://api.github.com/repos/acme/widget/issues', $tracker->requests);
    }

    public function testAPartOfThisAppThatCarriesTheMarkerIsNotFiledAgain(): void
    {
        $tracker = new ScriptedIssueTracker();
        $first = $this->fileOnce($tracker);

        $again = $this->fileOnce($tracker);

        self::assertSame([$first, 1], [$again, count($tracker->filed())]);
    }

    public function testARetryAfterACreateGitHubStoredButAnsweredAsFailedFilesNoSecondPart(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAStoredCreate = 502;

        try {
            (void) $this->fileOnce($tracker);
            self::fail('A create answered with 502 was reported as filed.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('GitHub answered 502 to a write.', $failed->getMessage());
        }

        $tracker->answerToAStoredCreate = null;

        self::assertSame(1, $this->fileOnce($tracker));
        self::assertCount(1, $tracker->filed());
    }

    public function testAPersonsIssueThatCarriesTheMarkerDoesNotStandForThePart(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->byPerson('Copied: '.self::MARKER);

        self::assertSame(2, $this->fileOnce($tracker));
    }

    public function testAPullRequestOfThisAppThatCarriesTheMarkerDoesNotStandForThePart(): void
    {
        $tracker = new ScriptedIssueTracker();
        (void) $this->fileOnce($tracker);
        $tracker->issues[0]['pull_request'] = ['url' => 'https://api.github.com/x'];

        self::assertSame(2, $this->fileOnce($tracker));
    }

    public function testThePartIsSearchedAmongTheIssuesUpdatedFromADayBeforeThePlan(): void
    {
        $tracker = new ScriptedIssueTracker();

        (void) $this->fileOnce($tracker);

        self::assertSame('GET https://api.github.com/repos/acme/widget/issues?state=all&since=2026-11-27T12:00:00Z&sort=created&direction=asc&per_page=100&page=1', $tracker->requests[0]);
    }

    public function testAPartOnASecondPageIsFound(): void
    {
        $tracker = new ScriptedIssueTracker();
        foreach (range(1, 100) as $other) {
            $tracker->byPerson('Other issue '.$other);
        }

        $number = $this->fileOnce($tracker);

        self::assertSame([101, 101], [$number, $this->fileOnce($tracker)]);
    }

    public function testIssuesThatCannotBeReadFileNothing(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->refusal = 500;

        try {
            (void) $this->fileOnce($tracker);
            self::fail('A part was filed without the read of the issues filed since the plan.');
        } catch (ForgeReadFailed $failed) {
            self::assertSame('GitHub answered 500 to a read of the issues.', $failed->getMessage());
        }

        self::assertCount(1, $tracker->requests);
    }

    public function testAPartGitHubStoredWithoutTheMarkerIsAFailedCreate(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub stored the part without this App\'s mark or its marker, so no retry would find it.'));

        (void) $this->fileOnce($tracker);
    }

    private function fileOnce(ScriptedIssueTracker $tracker): int
    {
        return $tracker->parts()->fileOnce(ScriptedIssueTracker::PROJECT, new PartIssue('Read the cart', 'Part 1 of 2 of #7.', self::MARKER, new IssueLabels('ready', 'priority:high'), new DateTimeImmutable('2026-11-28T12:00:00Z')));
    }
}
