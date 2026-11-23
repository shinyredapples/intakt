<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Dashboard\UseCase\StopReview\ReviewerStops;
use Intakt\Core\Dashboard\UseCase\StopReview\StopReviewController;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(StopReviewController::class)]
#[CoversClass(ReviewerStops::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(ShowReviewController::class)]
#[UsesClass(RoundLock::class)]
final class StopReviewControllerTest extends DashboardPageTestCase
{
    public function testARunningReviewerRowShowsAStopFormForItsReviewerId(): void
    {
        $this->runningReviewer();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('/reviews/round-1/reviewers/round-1-security/stop', $crawler->filter('form.stop-reviewer')->attr('action'));
        self::assertNotSame('', $crawler->filter('form.stop-reviewer input[name=_token]')->attr('value'));
    }

    // Stopping ends the reviewer as blocked, so the button asks for a second click first (DESIGN.md, Buttons: danger).
    public function testTheStopButtonIsADangerButtonThatAsksToBeClickedAgain(): void
    {
        $this->runningReviewer();

        $button = $this->browser->request('GET', '/reviews/round-1/record')->filter('form.stop-reviewer button[type=submit]');

        self::assertSame('btn danger sm', $button->attr('class'));
        self::assertSame('Click again to stop', $button->attr('data-arm-confirm'));
    }

    public function testAReviewerThatAnsweredOrFailedShowsNoStopForm(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->startReviewer('round-1', 'style');
        $this->ledger->failReviewer('round-1', 'style', 'boom');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('form.stop-reviewer'));
    }

    public function testAPressRecordsOneStopRequestKeyedByTheReviewerIdAndRedirectsWithAFlash(): void
    {
        $this->runningReviewer();

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/reviews/round-1');
        $recorded = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false);
        self::assertCount(1, $recorded);
        self::assertSame(['round' => 'round-1', 'reviewer' => 'round-1-security'], json_decode($recorded[0]->event->payload, true));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Asked the reviewer to stop.');
    }

    public function testAStopWhileAnotherPressForTheRoundRunsRecordsNothingAndSaysSo(): void
    {
        $this->runningReviewer();
        $token = $this->token();
        $held = $this->holdReviewLock('round-1');

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $token]);
        $held->release();

        self::assertResponseRedirects('/reviews/round-1', 303);
        $this->assertNothingRequested();
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Another press for this review is still running, so nothing was stopped.');
    }

    public function testAStopInALaterRoundWaitsForAPressHeldOnTheReviewsFirstRound(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'security');
        $token = $this->token();
        $held = $this->holdReviewLock('round-1');

        $this->browser->request('POST', '/reviews/round-2/reviewers/round-2-security/stop', ['_token' => $token]);
        $held->release();

        self::assertResponseRedirects('/reviews/round-2', 303);
        $this->assertNothingRequested();
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Another press for this review is still running, so nothing was stopped.');
    }

    public function testTheRoundStaysLockedUntilTheStopAnswerIsBuilt(): void
    {
        $this->runningReviewer();
        $token = $this->token();
        $freeWhileAnswering = $this->reviewFreeWhileAnswering('round-1');

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertFalse($freeWhileAnswering());
        $this->holdReviewLock('round-1')->release();
    }

    // A round of its own, because the file of a round another test locked stays in the directory.
    public function testAStopWithoutATokenLeavesNoLockFileBehind(): void
    {
        $round = 'fresh-'.bin2hex(random_bytes(8));
        $this->ledger->startRecordedRoundOf('feat/thing', $round, 1);
        $this->ledger->startReviewer($round, 'security');
        $before = self::reviewLockFiles();

        $this->browser->request('POST', '/reviews/'.$round.'/reviewers/'.$round.'-security/stop');

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was stopped."', BadRequestHttpException::class));
        self::assertSame([], array_values(array_diff(self::reviewLockFiles(), $before)));
    }

    public function testAStopForARoundTheLedgerDoesNotKnowLeavesNoLockFileBehind(): void
    {
        $token = $this->token();
        $before = self::reviewLockFiles();
        $round = 'unknown-'.bin2hex(random_bytes(8));

        $this->browser->request('POST', '/reviews/'.$round.'/reviewers/'.$round.'-security/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no running reviewer with this id in a round still in review."', NotFoundHttpException::class));
        self::assertSame([], array_values(array_diff(self::reviewLockFiles(), $before)));
    }

    public function testARefusedStopLeavesTheRoundFreeForTheNextPress(): void
    {
        $this->runningReviewer();

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop');

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was stopped."', BadRequestHttpException::class));
        $this->holdReviewLock('round-1')->release();
    }

    public function testAStopIsNotHeldBackByAPressForAnotherRound(): void
    {
        $this->runningReviewer();
        $token = $this->token();
        $held = $this->holdReviewLock('round-9');

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $token]);
        $held->release();

        self::assertCount(1, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false));
    }

    public function testASecondPressForAStillRunningReviewerRecordsASecondRequestAndAnswersLikeTheFirst(): void
    {
        $this->runningReviewer();
        $token = $this->token();

        foreach ([1, 2] as $_) {
            $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $token]);

            self::assertResponseStatusCodeSame(303);
            self::assertResponseRedirects('/reviews/round-1');
            $this->browser->followRedirect();
            self::assertSelectorTextContains('.flash-success', 'Asked the reviewer to stop.');
        }

        $recorded = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false);
        self::assertCount(2, $recorded);
        self::assertSame(['round' => 'round-1', 'reviewer' => 'round-1-security'], json_decode($recorded[0]->event->payload, true));
        self::assertSame(['round' => 'round-1', 'reviewer' => 'round-1-security'], json_decode($recorded[1]->event->payload, true));
    }

    public function testAMissingTokenRecordsNothing(): void
    {
        $this->runningReviewer();

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop');

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was stopped."', BadRequestHttpException::class));
        $this->assertNothingRequested();
    }

    public function testAWrongTokenRecordsNothing(): void
    {
        $this->runningReviewer();

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was stopped."', BadRequestHttpException::class));
        $this->assertNothingRequested();
    }

    public function testAForeignHostRecordsNothing(): void
    {
        $this->runningReviewer();
        $token = $this->token();

        $this->browser->request('POST', 'http://evil.example/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(400);
        $this->assertNothingRequested();
    }

    public function testAReviewerThatIsNotRunningRecordsNothing(): void
    {
        $this->runningReviewer();
        $this->ledger->answer('round-1', 'security', 0);

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no running reviewer with this id in a round still in review."', NotFoundHttpException::class));
        $this->assertNothingRequested();
    }

    public function testAJudgedRoundRecordsNothing(): void
    {
        $this->runningReviewer();
        $this->ledger->judgeRound('round-1');

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no running reviewer with this id in a round still in review."', NotFoundHttpException::class));
        $this->assertNothingRequested();
    }

    public function testAReviewerOfARoundThatEndedWithoutAJudgesCallRecordsNothing(): void
    {
        $this->runningReviewer();
        $token = $this->token();
        self::clockAt('2026-10-30T12:00:00+00:00');

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-1-security/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no running reviewer with this id in a round still in review."', NotFoundHttpException::class));
        $this->assertNothingRequested();
    }

    public function testAnUnknownReviewerIdRecordsNothing(): void
    {
        $this->runningReviewer();

        $this->browser->request('POST', '/reviews/round-1/reviewers/conversation-9/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger shows no running reviewer with this id in a round still in review."', NotFoundHttpException::class));
        $this->assertNothingRequested();
    }

    public function testAGetToTheStopRouteIsRefused(): void
    {
        $this->runningReviewer();

        $this->browser->request('GET', '/reviews/round-1/reviewers/round-1-security/stop');

        self::assertResponseStatusCodeSame(405);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780/reviews/round-1/reviewers/round-1-security/stop": Method Not Allowed (Allow: POST)"', MethodNotAllowedHttpException::class));
    }

    // The container keeps the ledger double across the page that hands out the token and the post that uses it.
    protected function setUp(): void
    {
        parent::setUp();
        $this->browser->disableReboot();
    }

    private function runningReviewer(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
    }

    private function assertNothingRequested(): void
    {
        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false));
    }

    // Read from a page's form, because the token belongs to the browser's session.
    private function token(): string
    {
        $this->ledger->startRecordedRoundOf('feat/token-page', 'token-page', 1);
        $this->ledger->startReviewer('token-page', 'security');

        return $this->browser->request('GET', '/reviews/token-page/record')->filter('form.stop-reviewer input[name=_token]')->attr('value') ?? '';
    }
}
