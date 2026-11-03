<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Dashboard\UseCase\StopReviewer\ReviewerStops;
use Intakt\Core\Dashboard\UseCase\StopReviewer\StopReviewerController;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(StopReviewerController::class)]
#[CoversClass(ReviewerStops::class)]
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
final class StopReviewerControllerTest extends DashboardPageTestCase
{
    use AssertsLoggedErrors;

    private const string STOP = '/reviews/round-1/reviewers/round-1-security/stop';

    private const string NOT_RUNNING = 'The ledger shows no running reviewer with this id in this round.';

    private const string BAD_TOKEN = 'The form token is missing or wrong, so nothing was stopped.';

    // The browser keeps the session across the page that hands out the token and the post that uses it.
    protected function setUp(): void
    {
        parent::setUp();
        $this->browser->disableReboot();
    }

    public function testTheButtonRecordsAStopRequestForTheRunningReviewerAndRedirectsBack(): void
    {
        $this->givenARunningReviewer();

        $this->browser->request('POST', self::STOP, ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/reviews/round-1');
        $requests = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested));
        self::assertCount(1, $requests);
        self::assertSame('{"round":"round-1","reviewer":"round-1-security"}', $requests[0]->event->payload);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Asked the security reviewer to stop.');
    }

    public function testOnlyARunningReviewerHasAStopButton(): void
    {
        $this->givenARunningReviewer();
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 0);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['/reviews/round-1/reviewers/round-1-security/stop'], $crawler->filter('form.stop-reviewer')->each(static fn ($form): ?string => $form->attr('action')));
    }

    public function testAJudgedRoundShowsNoStopButton(): void
    {
        $this->givenARunningReviewer();
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('form.stop-reviewer'));
    }

    public function testAMissingTokenStopsNothing(): void
    {
        $this->givenARunningReviewer();

        $this->browser->request('POST', self::STOP);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "%s"', BadRequestHttpException::class, self::BAD_TOKEN));
        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested)));
    }

    public function testAWrongTokenStopsNothing(): void
    {
        $this->givenARunningReviewer();

        $this->browser->request('POST', self::STOP, ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "%s"', BadRequestHttpException::class, self::BAD_TOKEN));
        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested)));
    }

    public function testStoppingAReviewerThatAlreadyAnsweredIsNotFound(): void
    {
        $this->givenARunningReviewer();
        $token = $this->token();
        $this->ledger->answer('round-1', 'security', 0);

        $this->browser->request('POST', self::STOP, ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "%s"', NotFoundHttpException::class, self::NOT_RUNNING));
        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested)));
    }

    public function testStoppingAReviewerThatIsAlreadyStoppedIsNotFound(): void
    {
        $this->givenARunningReviewer();
        $token = $this->token();
        $this->ledger->stopReviewer('round-1', 'security');

        $this->browser->request('POST', self::STOP, ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "%s"', NotFoundHttpException::class, self::NOT_RUNNING));
    }

    public function testStoppingAReviewerOfAJudgedRoundIsNotFound(): void
    {
        $this->givenARunningReviewer();
        $token = $this->token();
        $this->ledger->judgeRound('round-1');

        $this->browser->request('POST', self::STOP, ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "%s"', NotFoundHttpException::class, self::NOT_RUNNING));
    }

    public function testStoppingAReviewerOfAnotherRoundIsNotFound(): void
    {
        $this->givenARunningReviewer();

        $this->browser->request('POST', '/reviews/round-1/reviewers/round-9-security/stop', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "%s"', NotFoundHttpException::class, self::NOT_RUNNING));
    }

    public function testAStoppedReviewerShowsAsStoppedAfterAReload(): void
    {
        $this->givenARunningReviewer();
        $this->ledger->stopReviewer('round-1', 'security');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('stopped', $crawler->filter('#reviewer-round-1-security-state')->text());
        self::assertCount(0, $crawler->filter('form.stop-reviewer'));
    }

    public function testAGetToTheStopRouteIsRefused(): void
    {
        $this->givenARunningReviewer();

        $this->browser->request('GET', self::STOP);

        self::assertResponseStatusCodeSame(405);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780%s": Method Not Allowed (Allow: POST)"', MethodNotAllowedHttpException::class, self::STOP));
    }

    private function givenARunningReviewer(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
    }

    // Read from the page's form, because the token belongs to the browser's session.
    private function token(): string
    {
        return $this->browser->request('GET', '/reviews/round-1')->filter('form.stop-reviewer input[name=_token]')->attr('value') ?? '';
    }
}
