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
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(OnePerPlace::class)]
#[UsesClass(ReviewChange::class)]
final class MergedActEntryPageTest extends WebTestCase
{
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
        $review = $this->reviewed();
        $report = new ReviewReport($this->serializer());

        $page = $this->browser->request('GET', '/reviews/'.$review->roundId);

        self::assertResponseIsSuccessful();
        self::assertContains('F1+F2 P1 src/A.php:7: Text of A.', $report->asLines($review));
        self::assertSame([['F1', ['F2'], 'P1', 'Text of A.']], $this->jsonEntries($report->asJson($review)));
        self::assertSame([['F1+F2', 'P1', 'Text of A.', 'act']], $this->pageRows($page));
    }

    private function reviewed(): ChangeReview
    {
        $fixture = $this->fixture;
        $vendor = new VerdictPerRole([
            'Look at part 1.' => new Verdict(Decision::Changes, [$this->findingAt('A', Severity::P2)], [], []),
            'Look at part 2.' => new Verdict(Decision::Changes, [$this->findingAt('B', Severity::P1)], [], []),
        ]);
        $delivered = 0;
        $work = static function () use ($fixture, $vendor, &$delivered): void {
            foreach (array_slice($fixture->bus->messages, $delivered) as $message) {
                $fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($fixture->judgings() as $judging) {
                $fixture->judge($vendor)($judging);
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
        );

        return $reviewChange->review($this->request(), new IgnoredProgress());
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

    private function request(): ChangeReviewRequest
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );
        $panel = new Panel($members, QuorumPolicy::All);

        return new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed}>
     */
    private function jsonEntries(string $json): array
    {
        $output = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($output);
        self::assertIsArray($output['act_list']);

        return array_values(array_map(static fn (array $entry): array => [$entry['id'], $entry['merged'], $entry['severity'], $entry['text']], $output['act_list']));
    }

    /**
     * @return list<list<string>>
     */
    private function pageRows(Crawler $page): array
    {
        return $page->filter('table.findings tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
    }
}
