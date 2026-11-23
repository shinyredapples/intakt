<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Dashboard\UseCase\StopReview\ReviewerStops;
use Intakt\Core\Dashboard\UseCase\StopRound\StopRoundController;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(StopRoundController::class)]
#[CoversClass(ReviewerStops::class)]
#[CoversClass(FormPress::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(ShowReviewController::class)]
final class StopRoundControllerTest extends DashboardPageTestCase
{
    public function testARunningRoundShowsAStopFormForTheRoundInThePageHeader(): void
    {
        $this->runningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('/reviews/round-1/stop', $crawler->filter('.page-head .actions #round-round-1-now-stop form.stop-round')->attr('action'));
        self::assertNotSame('', $crawler->filter('form.stop-round input[name=_token]')->attr('value'));
    }

    // Stopping ends every running reviewer as blocked, so the button asks for a second click first (DESIGN.md, Buttons: danger).
    public function testTheStopButtonIsADangerButtonThatAsksToBeClickedAgain(): void
    {
        $this->runningRound();

        $button = $this->browser->request('GET', '/reviews/round-1')->filter('form.stop-round button[type=submit]');

        self::assertSame('Stop', $button->text());
        self::assertSame('btn danger', $button->attr('class'));
        self::assertSame('Click again to stop the round', $button->attr('data-arm-confirm'));
    }

    public function testThePageOfALaterRoundStopsTheRoundThatRunsNow(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'security');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('/reviews/round-2/stop', $crawler->filter('form.stop-round')->attr('action'));
    }

    public function testARoundBeforeItsFirstReviewerStartsShowsTheStopForm(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('/reviews/round-1/stop', $crawler->filter('#round-round-1-now-stop > form.stop-round')->attr('action'));
    }

    public function testARoundWhoseReviewersFinishedButIsNotJudgedShowsTheStopForm(): void
    {
        $this->runningRound();
        $this->ledger->answer('round-1', 'security', 0);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('/reviews/round-1/stop', $crawler->filter('#round-round-1-now-stop > form.stop-round')->attr('action'));
    }

    public function testAJudgedRoundShowsNoStopForm(): void
    {
        $this->runningRound();
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('#round-round-1-now-stop'));
    }

    public function testAPressRecordsOneStopRequestForEachRunningReviewerAndRedirectsWithAFlash(): void
    {
        $this->runningRound();
        $this->ledger->startReviewer('round-1', 'style');
        $this->ledger->startReviewer('round-1', 'tests');
        $this->ledger->answer('round-1', 'tests', 0);

        $this->browser->request('POST', '/reviews/round-1/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/reviews/round-1');
        self::assertSame([
            ['round' => 'round-1', 'reviewer' => 'round-1-security'],
            ['round' => 'round-1', 'reviewer' => 'round-1-style'],
        ], $this->requested());
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Asked 2 running reviewers to stop.');
    }

    public function testAPressForOneRunningReviewerNamesOneReviewer(): void
    {
        $this->runningRound();

        $this->browser->request('POST', '/reviews/round-1/stop', ['_token' => $this->token()]);
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'Asked 1 running reviewer to stop.');
    }

    public function testAMissingTokenRecordsNothing(): void
    {
        $this->runningRound();

        $this->browser->request('POST', '/reviews/round-1/stop');

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was stopped."', BadRequestHttpException::class));
        self::assertSame([], $this->requested());
    }

    // The token of a reviewer's own Stop belongs to another form, so it does not stop the whole round.
    public function testATokenOfTheReviewersStopRecordsNothing(): void
    {
        $this->runningRound();
        $token = $this->browser->request('GET', '/reviews/round-1/record')->filter('form.stop-reviewer input[name=_token]')->attr('value') ?? '';

        $this->browser->request('POST', '/reviews/round-1/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was stopped."', BadRequestHttpException::class));
        self::assertSame([], $this->requested());
    }

    public function testAForeignHostRecordsNothing(): void
    {
        $this->runningRound();
        $token = $this->token();

        $this->browser->request('POST', 'http://evil.example/reviews/round-1/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->requested());
    }

    public function testAPressWhileNoReviewerRunsRecordsNothingAndSaysSo(): void
    {
        $this->runningRound();
        $this->ledger->answer('round-1', 'security', 0);

        $this->browser->request('POST', '/reviews/round-1/stop', ['_token' => $this->token()]);

        self::assertResponseRedirects('/reviews/round-1', 303);
        self::assertSame([], $this->requested());
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'No reviewer runs in this round right now, so nothing was stopped.');
    }

    public function testAPressBeforeTheFirstReviewerStartsRecordsNothing(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        $this->browser->request('POST', '/reviews/round-1/stop', ['_token' => $this->token()]);

        self::assertResponseRedirects('/reviews/round-1', 303);
        self::assertSame([], $this->requested());
    }

    public function testAJudgedRoundRecordsNothing(): void
    {
        $this->runningRound();
        $this->ledger->judgeRound('round-1');

        $this->browser->request('POST', '/reviews/round-1/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no round still in review with this id."', NotFoundHttpException::class));
        self::assertSame([], $this->requested());
    }

    public function testARoundThatEndedWithoutAJudgesCallRecordsNothing(): void
    {
        $this->runningRound();
        $token = $this->token();
        self::clockAt('2026-10-30T12:00:00+00:00');

        $this->browser->request('POST', '/reviews/round-1/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no round still in review with this id."', NotFoundHttpException::class));
        self::assertSame([], $this->requested());
    }

    public function testAnUnknownRoundRecordsNothing(): void
    {
        $this->runningRound();

        $this->browser->request('POST', '/reviews/round-9/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no round still in review with this id."', NotFoundHttpException::class));
        self::assertSame([], $this->requested());
    }

    public function testAGetToTheStopRouteIsRefused(): void
    {
        $this->runningRound();

        $this->browser->request('GET', '/reviews/round-1/stop');

        self::assertResponseStatusCodeSame(405);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780/reviews/round-1/stop": Method Not Allowed (Allow: POST)"', MethodNotAllowedHttpException::class));
    }

    // The container keeps the ledger double across the page that hands out the token and the post that uses it.
    protected function setUp(): void
    {
        parent::setUp();
        $this->browser->disableReboot();
    }

    private function runningRound(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
    }

    // Read from a page's form, because the token belongs to the browser's session.
    private function token(): string
    {
        $this->ledger->startRecordedRoundOf('feat/token-page', 'token-page', 1);
        $this->ledger->startReviewer('token-page', 'security');

        return $this->browser->request('GET', '/reviews/token-page')->filter('form.stop-round input[name=_token]')->attr('value') ?? '';
    }

    /**
     * @return list<mixed>
     */
    private function requested(): array
    {
        return array_map(static fn ($row): mixed => json_decode($row->event->payload, true), iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false));
    }
}
