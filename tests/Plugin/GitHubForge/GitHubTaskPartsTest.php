<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskParts;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
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
            $this->fileOnce($tracker);
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
        $this->fileOnce($tracker);
        $tracker->issues[0]['pull_request'] = ['url' => 'https://api.github.com/x'];
        $tracker->ledger = new InMemoryLedger();

        self::assertSame(2, $this->fileOnce($tracker));
    }

    public function testThePartIsSearchedAmongTheIssuesUpdatedFromADayBeforeThePlan(): void
    {
        $tracker = new ScriptedIssueTracker();

        $this->fileOnce($tracker);

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
            $this->fileOnce($tracker);
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

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered the create of part #1 without this App\'s mark or its marker, so the next run checks that issue.'));

        $this->fileOnce($tracker);
    }

    public function testTwoFileOnceCallsWhoseFirstCreateIsUnconfirmedFileExactlyOneIssue(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $this->unconfirmed($tracker);
        $tracker->storedOtherwise = [];

        $again = $this->fileOnce($tracker);

        self::assertSame([1, 1], [$again, count($tracker->filed())]);
    }

    public function testTheNumberOfACreateIsKeptBeforeItsAnswerIsCheckedForTheMarker(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];

        $this->unconfirmed($tracker);

        self::assertSame([LedgerEventKind::PartIssueCreated], $tracker->ledger->kinds());
        self::assertSame([['project' => 'widget', 'issue' => 1, 'marker' => self::MARKER]], $tracker->ledger->payloads());
    }

    public function testAnUnconfirmedPartWithoutTheMarkerGetsTheMarkerWrittenIntoIt(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $this->unconfirmed($tracker);
        $tracker->storedOtherwise = [];

        $this->fileOnce($tracker);

        self::assertSame("Part 1 of 2 of #7.\n\n".self::MARKER, $tracker->issues[0]['body']);
        self::assertSame(['GET https://api.github.com/repos/acme/widget/issues/1', 'PATCH https://api.github.com/repos/acme/widget/issues/1'], array_slice($tracker->requests, -2));
    }

    public function testAnUnconfirmedPartStoredWithoutABodyGetsTheWholeBodyWithTheMarker(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => null];
        $this->unconfirmed($tracker);
        $tracker->storedOtherwise = [];

        $this->fileOnce($tracker);

        self::assertSame("Part 1 of 2 of #7.\n\n".self::MARKER, $tracker->issues[0]['body']);
    }

    public function testAKeptPartThatCarriesTheMarkerIsReturnedWithoutAWriteOrASearch(): void
    {
        $tracker = new ScriptedIssueTracker();
        $this->fileOnce($tracker);
        $before = count($tracker->requests);

        self::assertSame(1, $this->fileOnce($tracker));
        self::assertSame(['GET https://api.github.com/repos/acme/widget/issues/1'], array_slice($tracker->requests, $before));
    }

    public function testAMarkerGitHubWillNotKeepFailsTheRunAndFilesNoSecondIssue(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $this->unconfirmed($tracker);

        try {
            $this->fileOnce($tracker);
            self::fail('A part whose marker GitHub dropped was reported as filed.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('GitHub did not keep the marker written into part #1.', $failed->getMessage());
        }

        self::assertCount(1, $tracker->filed());
    }

    public function testAKeptPartThatCannotBeReadFilesNothing(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $this->unconfirmed($tracker);
        $tracker->refusal = 404;

        try {
            $this->fileOnce($tracker);
            self::fail('A kept part that could not be read was reported as filed.');
        } catch (ForgeReadFailed $failed) {
            self::assertSame('GitHub answered 404 to a read of the issues.', $failed->getMessage());
        }

        self::assertSame('GET https://api.github.com/repos/acme/widget/issues/1', array_last($tracker->requests));
        self::assertCount(1, $tracker->filed());
    }

    public function testAKeptNumberOfAnotherPartDoesNotStandForThisPart(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $this->unconfirmed($tracker);
        $tracker->storedOtherwise = [];

        self::assertSame(2, $this->fileOnce($tracker, '<!-- intakt:part task-sizing-abc 2 -->'));
    }

    public function testACreateWhoseNumberTheLedgerDoesNotKeepFails(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->ledger = new InMemoryLedger(0);

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub filed part #1, but the ledger did not keep its number: the ledger is down'));

        $this->fileOnce($tracker);
    }

    private function unconfirmed(ScriptedIssueTracker $tracker): void
    {
        try {
            $this->fileOnce($tracker);
            self::fail('A create whose answer lacks the marker was reported as filed.');
        } catch (ForgeWriteFailed) {
            self::assertCount(1, $tracker->filed());
        }
    }

    private function fileOnce(ScriptedIssueTracker $tracker, string $marker = self::MARKER): int
    {
        return $tracker->parts()->fileOnce(ScriptedIssueTracker::PROJECT, new PartIssue('Read the cart', 'Part 1 of 2 of #7.', $marker, new IssueLabels('ready', 'priority:high'), new DateTimeImmutable('2026-11-28T12:00:00Z')));
    }
}
