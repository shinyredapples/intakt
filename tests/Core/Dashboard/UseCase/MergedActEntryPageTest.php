<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Serializer\SerializerInterface;

// The round runs through the console's own use case, so the page is compared with what the console and its JSON print and nothing is seeded by hand.
#[Group('persistence')]
#[CoversClass(ShowReviewController::class)]
#[CoversClass(ReviewReport::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(StoredFindings::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(OnePerPlace::class)]
#[UsesClass(ReviewChange::class)]
final class MergedActEntryPageTest extends WebTestCase
{
    private const string DISCOUNT_DIFF = "diff --git a/src/discount.js b/src/discount.js\n@@ -10,6 +10,6 @@\n-  if (total >= 100) {\n+  if (total > 100) {\n@@ -40,3 +40,3 @@\n-old\n+new\n";

    private KernelBrowser $browser;

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testTwoReviewersReportingOnePlaceShowOneEntryWithBothIdsInTheConsoleTheJsonAndOnThePage(): void
    {
        $review = $this->reviewed([$this->findingAt('A', Severity::P2), $this->findingAt('B', Severity::P1)]);
        $report = new ReviewReport($this->serializer());

        $page = $this->browser->request('GET', '/reviews/'.$review->roundId.'/record');

        self::assertResponseIsSuccessful();
        self::assertContains('F1+F2 P1 src/A.php:7: Text of A.', $report->asLines($review));
        self::assertSame([['F1', ['F2'], 'P1', 'Text of A.']], $this->jsonEntries($report->asJson($review)));
        self::assertSame([['P1', 'F1', 'Text of A.', 'Also reported as F2', 'Fix now']], $this->pageRows($page->filter('.finding-card')));
    }

    // One change flagged at two lines of one hunk differs in place, so only the judge's link makes it one entry (decision D3 of #2334).
    public function testTwoReviewersReportingOneCauseInOneHunkShowOneEntryWithTheHigherSeverityInTheConsoleTheJsonAndOnThePage(): void
    {
        $first = new Finding('A', Severity::P1, 'src/discount.js', 12, null, 'The discount now starts above 100.', FixableBy::Builder, false, null, null);
        $second = new Finding('B', Severity::P2, 'src/discount.js', 14, null, 'An order of exactly 100 lost its discount.', FixableBy::Builder, false, null, null);
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act, 'F1')]);

        $review = $this->reviewed([$second, $first], $judgement, self::DISCOUNT_DIFF);
        $report = new ReviewReport($this->serializer());
        $page = $this->browser->request('GET', '/reviews/'.$review->roundId.'/record');

        self::assertResponseIsSuccessful();
        self::assertContains('F1+F2 P1 src/discount.js:14: An order of exactly 100 lost its discount.', $report->asLines($review));
        self::assertSame([['F1', ['F2'], 'P1', 'An order of exactly 100 lost its discount.']], $this->jsonEntries($report->asJson($review)));
        self::assertSame([['P1', 'F1', 'An order of exactly 100 lost its discount.', 'Also reported as F2', 'Fix now']], $this->pageRows($page->filter('.finding-card')));
    }

    public function testTwoFindingsTheJudgeLinksInTwoHunksStayTwoEntries(): void
    {
        $first = new Finding('A', Severity::P1, 'src/discount.js', 12, null, 'Text of A.', FixableBy::Builder, false, null, null);
        $second = new Finding('B', Severity::P2, 'src/discount.js', 41, null, 'Text of B.', FixableBy::Builder, false, null, null);

        $review = $this->reviewed([$first, $second], new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act, 'F1')]), self::DISCOUNT_DIFF);
        $page = $this->browser->request('GET', '/reviews/'.$review->roundId.'/record');

        self::assertSame([['P1', 'F1', 'Text of A.', 'Fix now'], ['P2', 'F2', 'Text of B.', 'Fix now']], $this->pageRows($page->filter('.finding-card')));
    }

    // The page's entry comes from the judge's ledger record and the act list from the stored round, each merged on its own, so three reports of one place must still give one entry alike.
    public function testThreeReviewersReportingOnePlaceShowTheEntryTheStoredActListHolds(): void
    {
        $review = $this->reviewed([$this->findingAt('A', Severity::P2), $this->findingAt('B', Severity::P2), $this->findingAt('C', Severity::P1)]);
        $act = $this->fixture->findingStore()->actListOf($this->fixture->round($review->roundId), $this->judgeDoneOf($review));

        $page = $this->browser->request('GET', '/reviews/'.$review->roundId.'/record');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $act);
        self::assertSame(array_map(static fn (Finding $entry): array => [$entry->severity->value, $entry->id, $entry->text, 'Also reported as '.implode(', ', [...$entry->merged]), 'Fix now'], $act), $this->pageRows($page->filter('.finding-card')));
    }

    // The symbol is place, because only a quote is checked against the change, so a finding with an absent symbol and no quote must reach the judge.
    public function testAFindingQuotingAnAbsentNameShowsItsReasonAndOneWithOnlyAnAbsentSymbolIsJudged(): void
    {
        $dropped = new Finding('A', Severity::P2, 'src/A.php', 9, null, 'Call `ghostName` first.', FixableBy::Builder, false, null, null);
        $judged = new Finding('B', Severity::P1, 'src/B.php', 12, 'ghostSymbol', 'Take the lock first.', FixableBy::Builder, false, null, null);
        $review = $this->reviewed([$dropped, $judged]);

        $page = $this->browser->request('GET', '/reviews/'.$review->roundId.'/record');

        self::assertResponseIsSuccessful();
        self::assertSame([['P1', 'F2', 'Take the lock first.', 'Fix now']], $this->pageRows($page->filter('.finding-card:not([data-dropped])')));
        self::assertSame([['P2', 'F1', 'Call ghostName first.', 'Quote not found in the code']], $this->pageRows($page->filter('[data-dropped]')));
        self::assertSame('Dropped before the judge: none of its 1 quoted code fragments is in the change', $page->filter('[data-dropped] .fc-reason')->text());
    }

    /**
     * @param non-empty-list<Finding> $findings
     */
    private function reviewed(array $findings, ?Judgement $judgement = null, string $diff = RoundFixture::DIFF): ChangeReview
    {
        $fixture = $this->fixture;
        $verdicts = [];
        foreach ($findings as $index => $finding) {
            $verdicts['Look at part '.($index + 1).'.'] = new Verdict(Decision::Changes, [$finding], [], []);
        }

        $vendor = new VerdictPerRole($verdicts);
        $judge = $judgement instanceof Judgement ? new ScriptedJudgement($judgement) : $vendor;
        $delivered = 0;
        $work = static function () use ($fixture, $vendor, $judge, &$delivered): void {
            foreach (array_slice($fixture->bus->messages, $delivered) as $message) {
                $fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($fixture->judgings() as $judging) {
                $fixture->judge($judge)($judging);
            }
        };
        $serializer = $this->serializer();
        $handler = $fixture->startHandlerOn($fixture->bus);
        $reviewChange = new ReviewChange(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))]),
            $fixture->transactions(),
            new StoredFindings($fixture->entities, $serializer, $fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($fixture->ledger(), $fixture->ledgerPayloads(), new ConfiguredPrices([])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($fixture->clock, $work),
            new FailureInWords(),
            $vendor,
            $fixture->noFailedJudgings(),
        );

        return $reviewChange->review($this->request(count($findings), $diff), new IgnoredProgress());
    }

    private function judgeDoneOf(ChangeReview $review): ?JudgeDone
    {
        return new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([]))->judgeDoneOf($review->roundId);
    }

    private function findingAt(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, 'src/A.php', 7, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }

    private function serializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
    }

    private function request(int $reviewers, string $diff): ChangeReviewRequest
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            range(1, $reviewers),
        );
        $panel = new Panel($members, QuorumPolicy::All);

        return new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, $diff, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
    }

    /**
     * @return list<list<mixed>>
     */
    private function jsonEntries(string $json): array
    {
        $output = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($output);
        self::assertIsArray($output['act_list']);
        $entries = [];

        foreach ($output['act_list'] as $entry) {
            self::assertIsArray($entry);
            $entries[] = [$entry['id'], $entry['merged'], $entry['severity'], $entry['text']];
        }

        return $entries;
    }

    /**
     * @return list<list<string>>
     */
    private function pageRows(Crawler $cards): array
    {
        return $cards->each(static fn (Crawler $card): array => $card->filter('.fc-id, .sev, .fc-text, .fc-merged, .fc-call')->each(static fn (Crawler $part): string => $part->text()));
    }
}
