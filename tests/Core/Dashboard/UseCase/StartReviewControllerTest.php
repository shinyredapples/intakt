<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\FormIsRefused;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Dashboard\UseCase\StartReview\ReviewStarts;
use Intakt\Core\Dashboard\UseCase\StartReview\StartReviewController;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelWithoutReviewers;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\StartReview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(StartReviewController::class)]
#[CoversClass(ReviewStarts::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundSource::class)]
#[UsesClass(ShowReviewController::class)]
#[CoversClass(RoundLock::class)]
final class StartReviewControllerTest extends DashboardPageTestCase
{
    public function testTheButtonStartsTheNextRoundOfTheListedChangeAndRedirectsBackWithAToastNamingItsNumber(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $commands = $this->commandBus();
        $commands->expects(self::once())->method('dispatch')
            ->with(self::equalTo(new StartReview('alpha', 'Add the thing.', new RequestedRound('feat/thing', 2, new ChangeSource('/repos/alpha', 'main')))))
            ->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-2', 'handler')]));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/reviews/round-1');
        $this->browser->followRedirect();
        self::assertSelectorTextSame('.toast', 'Started round 2.');
    }

    public function testTheStartToastCarriesNoRoundId(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willReturn(new Envelope(new stdClass(), [new HandledStamp('0193a7c2-5d1e-7a3b-9c4f-2e8d6b1a0f37', 'handler')]));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);
        $crawler = $this->browser->followRedirect();

        self::assertStringNotContainsString('0193a7c2-5d1e-7a3b-9c4f-2e8d6b1a0f37', $crawler->filter('.toast')->outerHtml());
    }

    public function testStartingARoundAddsNoLineAboveThePage(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-2', 'handler')]));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);
        $this->browser->followRedirect();

        self::assertSelectorNotExists('.flash');
    }

    public function testTheButtonCarriesTheNumberAfterTheLatestRoundThePageShows(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('3', $crawler->filter('form.start-review input[name=number]')->attr('value'));
    }

    public function testTheButtonIsLabeledWithTheNumberOfTheRoundItStarts(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Start round 3', trim($crawler->filter('form.start-review button[type=submit]')->text()));
    }

    // The start form is rendered for the stream's sake, and the in-review marker hides it, so only Stop shows.
    public function testARoundWithARunningReviewerOffersOnlyStop(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $this->assertOnlyStopIsOffered($crawler);
    }

    // Before its first reviewer starts the round is in review already, so Stop is offered and the start of the next round stays hidden.
    public function testARoundInReviewWithoutARunningReviewerOffersOnlyStop(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $this->assertOnlyStopIsOffered($crawler);
    }

    // Every reviewer finished but the judge has not called yet, so the round still runs.
    public function testARoundWhoseReviewersFinishedButIsNotJudgedOffersOnlyStop(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->answer('round-1', 'security', 0);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $this->assertOnlyStopIsOffered($crawler);
    }

    public function testAJudgedReviewOffersOnlyTheStartOfTheNextRound(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.now-runs'));
        self::assertCount(0, $crawler->filter('form.stop-round'));
        self::assertSame(['Start round 2'], $crawler->filter('.task-actions button')->each(static fn (Crawler $button): string => trim($button->text())));
    }

    // The ledger already holds round 2 when the stale page's number arrives, so only the number from the page lets the Review module refuse it.
    public function testAStalePageAsksForTheNumberItShowedAndNotTheNextOneInTheLedger(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-2', 2);
        $commands = $this->commandBus();
        $commands->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (StartReview $command): bool => 2 === $command->round->number))
            ->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-3', 'handler')]));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);

        self::assertResponseStatusCodeSame(303);
    }

    public function testAPostWithoutARoundNumberDispatchesNothing(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => 'two']);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form names no round number, so nothing was started."', FormIsRefused::class));
    }

    public function testAMissingTokenDispatchesNothing(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $this->browser->request('POST', '/reviews/round-1/start');

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was started."', FormIsRefused::class));
    }

    public function testAWrongTokenDispatchesNothing(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was started."', FormIsRefused::class));
    }

    public function testARoundThatIsNotNextRedirectsBackWithAnErrorLineAndNoTrace(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $refusal = RoundNotNext::for(2, 3);
        $commands = $this->commandBus();
        $commands->expects(self::once())->method('dispatch')->willThrowException($refusal);

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);

        self::assertResponseStatusCodeSame(303);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Round 2 was asked for');
        self::assertStringNotContainsString('Stack trace', (string) $this->browser->getResponse()->getContent());
    }

    public function testAProjectWithoutAReviewPanelIsAnsweredInPlainWords(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willThrowException(new PanelNotConfigured('alpha'));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);

        self::assertResponseStatusCodeSame(303);
        $this->browser->followRedirect();
        self::assertSelectorTextSame('.flash-error', 'This project has no reviewers set up yet, so nothing was started.');
        self::assertSelectorNotExists('.toast');
    }

    public function testAnotherPanelFailureKeepsItsOwnMessage(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willThrowException(new PanelWithoutReviewers());

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);

        $this->browser->followRedirect();
        self::assertSelectorTextSame('.flash-error', new PanelWithoutReviewers()->getMessage());
    }

    public function testAFailureThatIsNotAReportableOneIsAServerErrorAndNotAnErrorLine(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willThrowException(new RuntimeException('the review store is gone'));

        $crawler = $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);

        self::assertResponseStatusCodeSame(500);
        self::assertLoggedError(LogLevel::ERROR, sprintf('A dashboard page failed, reference %s.', $crawler->filter('main code')->text()));
        self::assertLoggedError(LogLevel::CRITICAL, sprintf('Uncaught PHP Exception %s: "the review store is gone"', RuntimeException::class));
    }

    // The second press arrives while the first one's command runs, so the order is forced and not left to timing; both workers read the same ledger and send to the same bus, so without the lock the second would start a round too.
    public function testTwoPressesForTheSameRoundArrivingTogetherStartOneRoundAndRefuseTheOther(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $commands = self::createStub(MessageBusInterface::class);
        self::getContainer()->set('command.bus', $commands);
        $token = $this->token();
        $presses = 0;
        $secondLines = [];
        $commands->method('dispatch')->willReturnCallback(function (StartReview $command) use ($commands, $token, &$presses, &$secondLines): Envelope {
            if (1 === ++$presses) {
                $secondLines = $this->pressInAnotherWorker($commands, '/reviews/round-1/start', ['_token' => $token, 'number' => '2']);
            }
            $number = $command->round->number;
            self::assertIsInt($number);
            $this->ledger->startRecordedRoundOf($command->round->change, 'round-'.$number.'-'.$presses, $number);

            return new Envelope(new stdClass(), [new HandledStamp('round-2', 'handler')]);
        });

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $token, 'number' => '2']);
        $this->browser->followRedirect();

        self::assertSelectorTextSame('.toast', 'Started round 2.');
        self::assertSame(['error' => ['Another press for this review is still running, so nothing was started.']], $secondLines);
        self::assertSame(1, $presses);
        self::assertSame([1, 2], $this->numbersStartedFor('feat/thing'));
    }

    // Every round page of one review shows the same start form and posts it to its own round, so a key from the address would let both presses through (#3021, D4 and D5).
    public function testTwoPressesForTheSameReviewFromTwoOfItsRoundPagesStartOneRoundAndRefuseTheOther(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-2', 2);
        $commands = self::createStub(MessageBusInterface::class);
        self::getContainer()->set('command.bus', $commands);
        $token = $this->token();
        $presses = 0;
        $secondLines = [];
        $commands->method('dispatch')->willReturnCallback(function (StartReview $command) use ($commands, $token, &$presses, &$secondLines): Envelope {
            if (1 === ++$presses) {
                $secondLines = $this->pressInAnotherWorker($commands, '/reviews/round-1/start', ['_token' => $token, 'number' => '3']);
            }
            $number = $command->round->number;
            self::assertIsInt($number);
            $this->ledger->startRecordedRoundOf($command->round->change, 'round-'.$number.'-'.$presses, $number);

            return new Envelope(new stdClass(), [new HandledStamp('round-3', 'handler')]);
        });

        $this->browser->request('POST', '/reviews/round-2/start', ['_token' => $token, 'number' => '3']);
        $this->browser->followRedirect();

        self::assertSelectorTextSame('.toast', 'Started round 3.');
        self::assertSame(['error' => ['Another press for this review is still running, so nothing was started.']], $secondLines);
        self::assertSame(1, $presses);
        self::assertSame([1, 2, 3], $this->numbersStartedFor('feat/thing'));
    }

    // A round of its own, because the file of a round another test locked stays in the directory.
    public function testAStartWithoutATokenLeavesNoLockFileBehind(): void
    {
        $round = 'fresh-'.bin2hex(random_bytes(8));
        $this->ledger->startRecordedRoundOf('feat/thing', $round, 1);
        $before = self::reviewLockFiles();

        $this->browser->request('POST', '/reviews/'.$round.'/start', ['number' => '2']);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was started."', FormIsRefused::class));
        self::assertSame([], array_values(array_diff(self::reviewLockFiles(), $before)));
    }

    public function testAStartForARoundTheLedgerDoesNotKnowLeavesNoLockFileBehind(): void
    {
        $this->commandBus()->expects(self::never())->method('dispatch');
        $token = $this->token();
        $before = self::reviewLockFiles();

        $this->browser->request('POST', '/reviews/unknown-'.bin2hex(random_bytes(8)).'/start', ['_token' => $token, 'number' => '2']);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger holds no review with this round that can be started again."', NotFoundHttpException::class));
        self::assertSame([], array_values(array_diff(self::reviewLockFiles(), $before)));
    }

    public function testAStartWhileAnotherPressForTheRoundRunsDispatchesNothingAndSaysSo(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');
        $token = $this->token();
        $held = $this->holdReviewLock('round-1');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $token, 'number' => '2']);
        $held->release();

        self::assertResponseRedirects('/reviews/round-1', 303);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Another press for this review is still running, so nothing was started.');
    }

    public function testTheRoundStaysLockedUntilTheStartAnswerIsBuilt(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-2', 'handler')]));
        $token = $this->token();
        $freeWhileAnswering = $this->reviewFreeWhileAnswering('round-1');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $token, 'number' => '2']);

        self::assertResponseRedirects('/reviews/round-1', 303);
        self::assertFalse($freeWhileAnswering());
        $this->holdReviewLock('round-1')->release();
    }

    public function testAStartIsNotHeldBackByAPressForAnotherRound(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-2', 'handler')]));
        $token = $this->token();
        $held = $this->holdReviewLock('round-9');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $token, 'number' => '2']);
        $held->release();
        $this->browser->followRedirect();

        self::assertSelectorTextSame('.toast', 'Started round 2.');
    }

    public function testAStartWithoutATokenIsRefusedAlsoWhileTheRoundIsBusy(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $held = $this->holdReviewLock('round-1');

        $this->browser->request('POST', '/reviews/round-1/start', ['number' => '2']);
        $held->release();

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was started."', FormIsRefused::class));
    }

    public function testARefusedStartShowsNoToast(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::once())->method('dispatch')->willThrowException(RoundNotNext::for(2, 3));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);
        $this->browser->followRedirect();

        self::assertSelectorNotExists('.toast');
    }

    public function testARoundWithoutARecordedRepositoryCannotBeStarted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token(), 'number' => '2']);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger holds no review with this round that can be started again."', NotFoundHttpException::class));
    }

    public function testAGetToTheStartRouteIsRefused(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        $this->browser->request('GET', '/reviews/round-1/start');

        self::assertResponseStatusCodeSame(405);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780/reviews/round-1/start": Method Not Allowed (Allow: POST)"', MethodNotAllowedHttpException::class));
    }

    public function testThePageThatHoldsTheButtonStillRendersFromTheLedgerAlone(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertResponseIsSuccessful();
        self::assertSame('/reviews/round-1/start', $crawler->filter('form.start-review')->attr('action'));
        self::assertNotSame('', $crawler->filter('form.start-review input[name=_token]')->attr('value'));
        self::assertSame('btn primary', $crawler->filter('.page-head .actions form.start-review button[type=submit]')->attr('class'));
    }

    public function testAPageWhoseRoundRecordedNoRepositoryShowsNoButtonAndSaysWhy(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('form.start-review'));
        self::assertSelectorTextContains('.no-start', 'started before Intakt recorded');
    }

    public function testOnlyTheLatestRoundDecidesWhetherTheButtonShows(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('form.start-review'));
    }

    // The container keeps the ledger double and the bus mock across the page that hands out the token and the post that uses it.
    protected function setUp(): void
    {
        parent::setUp();
        $this->browser->disableReboot();
    }

    // The start form stays rendered for the judged round's stream, so the in-review marker is what keeps it from showing beside Stop.
    private function assertOnlyStopIsOffered(Crawler $crawler): void
    {
        self::assertSame(['round-round-1-now-runs', 'round-round-1-now-stop', 'start-review'], $crawler->filter('.task-actions > *')->each(static fn (Crawler $child): string => $child->attr('id') ?? (string) $child->attr('class')));
        self::assertSame(['stop-round'], $crawler->filter('#round-round-1-now-stop > *')->each(static fn (Crawler $child): string => (string) $child->attr('class')));
        self::assertSame('Stop', trim($crawler->filter('form.stop-round button')->text()));
        self::assertCount(1, $crawler->filter('.task-actions:has(.now-runs) > form.start-review'));
    }

    private function commandBus(): MessageBusInterface&MockObject
    {
        $commands = $this->createMock(MessageBusInterface::class);
        self::getContainer()->set('command.bus', $commands);

        return $commands;
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<mixed>
     */
    // On the browser's session; the lines are read from its session, because the first press saves the session after it.
    private function pressInAnotherWorker(MessageBusInterface $commands, string $path, array $form): array
    {
        $kernel = $this->anotherWorker();
        $kernel->getContainer()->get('test.service_container')->set('command.bus', $commands);
        $request = Request::create('http://localhost:7780'.$path, 'POST', $form);
        foreach ($this->browser->getCookieJar()->all() as $cookie) {
            $request->cookies->set($cookie->getName(), $cookie->getValue());
        }

        try {
            $kernel->handle($request);
            $session = $request->getSession();
            assert($session instanceof FlashBagAwareSessionInterface);

            return $session->getFlashBag()->peekAll();
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return list<int>
     */
    private function numbersStartedFor(string $change): array
    {
        $numbers = [];
        foreach ($this->ledger->readOfKind(LedgerEventKind::ReviewStarted) as $row) {
            $payload = json_decode($row->event->payload, true);
            if (is_array($payload) && $change === ($payload['change'] ?? null) && is_int($payload['number'] ?? null)) {
                $numbers[] = $payload['number'];
            }
        }

        return $numbers;
    }

    // Read from a page's form, because the token belongs to the browser's session.
    private function token(string $round = 'round-1'): string
    {
        $this->ledger->startRecordedRoundOf('feat/token-page', $round.'-token', 1);

        return $this->browser->request('GET', '/reviews/'.$round.'-token')->filter('form.start-review input[name=_token]')->attr('value') ?? '';
    }
}
