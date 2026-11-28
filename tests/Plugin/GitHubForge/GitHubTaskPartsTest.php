<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\CreatedParts;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskParts;
use Intakt\Plugin\GitHubForge\Infrastructure\PartIssueCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\WriteRefused;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubTaskParts::class)]
#[CoversClass(PartIssueCalls::class)]
#[CoversClass(CreatedParts::class)]
#[CoversClass(WriteRefused::class)]
#[UsesClass(PartIssue::class)]
#[UsesClass(IssueLabels::class)]
final class GitHubTaskPartsTest extends TestCase
{
    private const string MARKER = '<!-- intakt:part task-sizing-abc 1 -->';
    private const string STOPPED = 'An earlier create of this part has no known outcome: it kept no issue number, GitHub did not refuse it and no issue carries its marker, so nothing was filed again; check the repository\'s issues for the part.';

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

    public function testAFiledPartIsKnownByItsNumberInItsProjectAlone(): void
    {
        $tracker = new ScriptedIssueTracker();
        $number = $this->fileOnce($tracker);

        $parts = $tracker->parts();

        self::assertSame([true, false, false], [$parts->isFiledPart(ScriptedIssueTracker::PROJECT, $number), $parts->isFiledPart(ScriptedIssueTracker::PROJECT, $number + 1), $parts->isFiledPart('other', $number)]);
    }

    public function testAPartIsNotKnownByItsNumberWhileTheAnswerToItsCreateIsLostAndIsOnceARetryFoundIt(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAStoredCreate = 502;

        try {
            $this->fileOnce($tracker);
            self::fail('A create answered with 502 was reported as filed.');
        } catch (ForgeWriteFailed) {
            self::assertFalse($tracker->parts()->isFiledPart(ScriptedIssueTracker::PROJECT, 1));
        }

        $tracker->answerToAStoredCreate = null;
        $found = $this->fileOnce($tracker);

        self::assertSame([1, true], [$found, $tracker->parts()->isFiledPart(ScriptedIssueTracker::PROJECT, $found)]);
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
            self::assertSame('GitHub answered 500 to a read of /repos/acme/widget/issues.', $failed->getMessage());
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

        self::assertSame([LedgerEventKind::PartIssueCreated, LedgerEventKind::PartIssueCreated], $tracker->ledger->kinds());
        self::assertSame([['project' => 'widget', 'repository' => '/repos/acme/widget', 'marker' => self::MARKER], ['project' => 'widget', 'repository' => '/repos/acme/widget', 'issue' => 1, 'marker' => self::MARKER]], $tracker->ledger->payloads());
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
            self::assertSame('GitHub answered 404 to a read of /repos/acme/widget/issues/1.', $failed->getMessage());
        }

        self::assertSame('GET https://api.github.com/repos/acme/widget/issues/1', array_last($tracker->requests));
        self::assertCount(1, $tracker->filed());
    }

    public function testAKeptPartGitHubAnswersWithAnotherIssueFilesNothing(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $this->unconfirmed($tracker);
        $tracker->issues[0]['number'] = 9;

        try {
            $this->fileOnce($tracker);
            self::fail('A kept part answered with another issue was reported as filed.');
        } catch (ForgeReadFailed $failed) {
            self::assertSame('GitHub answered the read of part #1 with something other than that issue.', $failed->getMessage());
        }

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
        $tracker->ledger = new InMemoryLedger(1);

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub filed part #1, but the ledger did not keep its number: the ledger is down'));

        $this->fileOnce($tracker);
    }

    public function testAPartOnALaterPageOfShortPagesIsFound(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->served = 1;
        $tracker->byPerson('Other issue 1');
        $tracker->byPerson('Other issue 2');
        $first = $this->fileOnce($tracker);
        $tracker->ledger = new InMemoryLedger();

        self::assertSame([$first, 1], [$this->fileOnce($tracker), count($tracker->filed())]);
    }

    public function testAPartKeptForAnotherRepositoryThanTheProjectsNowFilesAndWritesNothing(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $this->unconfirmed($tracker);
        $tracker->storedOtherwise = [];
        $requests = count($tracker->requests);

        try {
            $tracker->parts('acme/gadget')->fileOnce(ScriptedIssueTracker::PROJECT, $this->part(self::MARKER));
            self::fail('A number kept for another repository was used.');
        } catch (ForgeReadFailed $failed) {
            self::assertSame('The ledger kept this part\'s issue number for another repository than the project\'s now.', $failed->getMessage());
        }

        self::assertSame($requests, count($tracker->requests));
    }

    public function testACreateWhoseNumberTheLedgerDoesNotKeepIsNotFiledAgainWhenNoMarkerIsFound(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->storedOtherwise = ['body' => 'Part 1 of 2 of #7.'];
        $tracker->ledger = new InMemoryLedger(1);

        try {
            $this->fileOnce($tracker);
            self::fail('A create whose number the ledger refused was reported as filed.');
        } catch (ForgeWriteFailed) {
            self::assertCount(1, $tracker->filed());
        }

        $this->expectExceptionObject(new ForgeWriteFailed(self::STOPPED));

        $this->fileOnce($tracker);
    }

    #[DataProvider('refusals')]
    public function testACreateGitHubRefusedIsFiledByTheNextCallExactlyOnce(int $refusal): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAnUnstoredCreate = $refusal;

        try {
            $this->fileOnce($tracker);
            self::fail('A refused create was reported as filed.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame(sprintf('GitHub answered %d to a write.', $refusal), $failed->getMessage());
        }

        $tracker->answerToAnUnstoredCreate = null;

        self::assertSame([1, 1, 1], [$this->fileOnce($tracker), $this->fileOnce($tracker), count($tracker->filed())]);
    }

    public function testARefusedCreateWhoseBodyIsLostIsFiledByTheNextCallExactlyOnce(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAnUnstoredCreate = 422;
        $tracker->bodyOfAnUnstoredCreateLost = true;

        try {
            $this->fileOnce($tracker);
            self::fail('A refused create was reported as filed.');
        } catch (ForgeWriteFailed) {
        }

        $tracker->answerToAnUnstoredCreate = null;

        self::assertSame([1, 1], [$this->fileOnce($tracker), count($tracker->filed())]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusals(): iterable
    {
        yield 'the first refusal status' => [400];
        yield 'a refused credential' => [401];
        yield 'a forbidden write' => [403];
        yield 'an invalid issue' => [422];
        yield 'the last refusal status' => [499];
    }

    #[DataProvider('unknownOutcomes')]
    public function testACreateAnsweredWithoutAKnownOutcomeStopsTheNextCall(int $answer): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAnUnstoredCreate = $answer;

        try {
            $this->fileOnce($tracker);
            self::fail('A create answered with an error was reported as filed.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame(sprintf('GitHub answered %d to a write.', $answer), $failed->getMessage());
        }

        $tracker->answerToAnUnstoredCreate = null;

        try {
            $this->fileOnce($tracker);
            self::fail('A part whose earlier create has no known outcome was filed again.');
        } catch (ForgeWriteFailed $stopped) {
            self::assertSame(self::STOPPED, $stopped->getMessage());
        }

        self::assertSame([], $tracker->filed());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unknownOutcomes(): iterable
    {
        yield 'the status below the refusals' => [399];
        yield 'the status above the refusals' => [500];
        yield 'a bad gateway' => [502];
    }

    public function testARateLimitedCreateIsFiledByTheNextCallExactlyOnce(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAnUnstoredCreate = 403;
        $tracker->headersOfAnUnstoredCreate = ['retry-after: 60'];

        try {
            $this->fileOnce($tracker);
            self::fail('A rate limited create was reported as filed.');
        } catch (ForgeRateLimited $limited) {
            self::assertSame('The forge is rate limited until 2026-11-28T12:01:00+00:00.', $limited->getMessage());
        }

        $tracker->answerToAnUnstoredCreate = null;

        self::assertSame([1, 1], [$this->fileOnce($tracker), count($tracker->filed())]);
    }

    public function testARefusedCreateIsKeptInTheLedgerAfterItsIntent(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAnUnstoredCreate = 422;

        try {
            $this->fileOnce($tracker);
            self::fail('A refused create was reported as filed.');
        } catch (ForgeWriteFailed) {
            $place = ['project' => 'widget', 'repository' => '/repos/acme/widget', 'marker' => self::MARKER];
            self::assertSame([$place, [...$place, 'refused' => 'GitHub answered 422 to a write.']], $tracker->ledger->payloads());
        }
    }

    public function testARefusalTheLedgerDoesNotKeepStopsTheNextCall(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->answerToAnUnstoredCreate = 422;
        $tracker->ledger = new InMemoryLedger(1);

        try {
            $this->fileOnce($tracker);
            self::fail('A refused create was reported as filed.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('Nothing was filed (GitHub answered 422 to a write.), but the ledger did not keep that, so the next run stops until a person has checked the issues: the ledger is down', $failed->getMessage());
        }

        $tracker->answerToAnUnstoredCreate = null;
        $this->expectExceptionObject(new ForgeWriteFailed(self::STOPPED));

        $this->fileOnce($tracker);
    }

    public function testACreateWithoutATokenIsARefusalBecauseNothingWasSent(): void
    {
        $tracker = new ScriptedIssueTracker();

        try {
            $tracker->calls('contents:read')->created('/repos/acme/widget/issues', ['title' => 'Read the cart']);
            self::fail('A create without a token was sent.');
        } catch (WriteRefused $refused) {
            self::assertStringStartsWith('No GitHub write of an issue: ', $refused->failure->getMessage());
        }

        self::assertSame([], $tracker->requests);
    }

    public function testAnotherWriteGitHubRefusedFailsAsAnyFailedWrite(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->refusal = 422;

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered 422 to a write.'));

        $tracker->calls()->written('PATCH', '/repos/acme/widget/issues/1', ['body' => 'Part 1 of 2 of #7.']);
    }

    public function testAPartWhoseFilingIsHeldByAnotherRunIsNotFiledByThisOne(): void
    {
        $tracker = new ScriptedIssueTracker();
        $tracker->holdTheLock($this->part(self::MARKER));

        try {
            $this->fileOnce($tracker);
            self::fail('A part was filed while another run held its lock.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('Another run is filing this part.', $failed->getMessage());
        }

        self::assertSame([], $tracker->requests);
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
        return $tracker->parts()->fileOnce(ScriptedIssueTracker::PROJECT, $this->part($marker));
    }

    private function part(string $marker): PartIssue
    {
        return new PartIssue('Read the cart', 'Part 1 of 2 of #7.', $marker, new IssueLabels('ready', 'priority:high'), new DateTimeImmutable('2026-11-28T12:00:00Z'));
    }
}
