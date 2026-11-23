<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\StoppingAtTheLimitForRole;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

// The round runs through the console's own use case with a vendor that stops at the limit, so the page is compared with the outcome the console reports and nothing is seeded by hand.
#[Group('persistence')]
#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
#[UsesClass(ReviewChange::class)]
final class FailedReviewerPageTest extends WebTestCase
{
    private KernelBrowser $browser;

    private RoundFixture $fixture;

    public function testAReviewerStoppedAtTheLimitShowsAsFailedInPlainWordsBesideTheConsolesQuorumOutcome(): void
    {
        $review = $this->reviewedWith(new StoppingAtTheLimitForRole('Look at part 1.'));

        $page = $this->browser->request('GET', '/reviews/'.$review->roundId.'/record');

        self::assertResponseIsSuccessful();
        self::assertSame([['role-1', 'standard-model', 'Failed stopped at its output limit'], ['role-2', 'standard-model', 'Approved']], $this->reviewerRows($page));
        self::assertSame(['stopped at its output limit'], $page->filter('table.reviewers .reviewer-outcome .muted')->each(static fn (Crawler $cause): string => $cause->text()));
        self::assertSame(
            'Outcome: Blocked, decided by the quorum policy all over all 2 reviewers. 1 of 2 reviewers failed, and a failed reviewer gives no approval.',
            $page->filter('#round-'.$review->roundId.'-outcome')->text(),
        );
        self::assertSame('blocked', $review->decision->value);
        self::assertStringNotContainsString('Exception', $page->filter('main, body')->first()->text());
    }

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function reviewedWith(StoppingAtTheLimitForRole $vendor): ChangeReview
    {
        $fixture = $this->fixture;
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
        $serializer = self::getContainer()->get('serializer.stored');
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

        return $reviewChange->review($this->request(), new IgnoredProgress());
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
     * @return list<list<string>>
     */
    private function reviewerRows(Crawler $page): array
    {
        $rows = $page->filter('table.reviewers tbody tr')->each(static fn (Crawler $row): array => [$row->filter('td')->eq(0)->text(), $row->filter('td')->eq(1)->text(), $row->filter('td')->eq(2)->text()]);
        sort($rows);

        return $rows;
    }
}
