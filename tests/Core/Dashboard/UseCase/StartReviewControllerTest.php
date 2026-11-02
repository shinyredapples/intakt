<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Dashboard\UseCase\StartReview\StartReviewController;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\StartReview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(StartReviewController::class)]
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
final class StartReviewControllerTest extends DashboardPageTestCase
{
    // The container keeps the ledger double and the bus mock across the page that hands out the token and the post that uses it.
    protected function setUp(): void
    {
        parent::setUp();
        $this->browser->disableReboot();
    }

    public function testTheButtonStartsTheNextRoundOfTheListedChangeAndRedirectsBackWithTheRoundId(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $commands = $this->commandBus();
        $commands->expects(self::once())->method('dispatch')
            ->with(self::equalTo(new StartReview('alpha', 'feat/thing', 'Add the thing.', new ChangeSource('/repos/alpha', 'main'))))
            ->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-2', 'handler')]));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/reviews/round-1');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Started round round-2.');
    }

    public function testAMissingTokenDispatchesNothing(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $this->browser->request('POST', '/reviews/round-1/start');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAWrongTokenDispatchesNothing(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testARoundThatIsNotNextRedirectsBackWithAnErrorLineAndNoTrace(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $refusal = RoundNotNext::for(2, 3);
        $commands = $this->commandBus();
        $commands->expects(self::once())->method('dispatch')->willThrowException(new HandlerFailedException(new Envelope(new stdClass()), [$refusal]));

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(303);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Round 2 was asked for');
        self::assertStringNotContainsString('Stack trace', (string) $this->browser->getResponse()->getContent());
    }

    public function testARoundWithoutARecordedRepositoryCannotBeStarted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $this->browser->request('POST', '/reviews/round-1/start', ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGetToTheStartRouteIsRefused(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        $this->browser->request('GET', '/reviews/round-1/start');

        self::assertResponseStatusCodeSame(405);
    }

    public function testThePageThatHoldsTheButtonStillRendersFromTheLedgerAlone(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->commandBus()->expects(self::never())->method('dispatch');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertResponseIsSuccessful();
        self::assertSame('/reviews/round-1/start', $crawler->filter('form.start-review')->attr('action'));
        self::assertNotSame('', $crawler->filter('form.start-review input[name=_token]')->attr('value'));
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

    private function commandBus(): MessageBusInterface&MockObject
    {
        $commands = $this->createMock(MessageBusInterface::class);
        self::getContainer()->set('command.bus', $commands);

        return $commands;
    }

    // Read from a page's form, because the token belongs to the browser's session.
    private function token(string $round = 'round-1'): string
    {
        $this->ledger->startRecordedRoundOf('feat/token-page', $round.'-token', 1);

        return $this->browser->request('GET', '/reviews/'.$round.'-token')->filter('form.start-review input[name=_token]')->attr('value') ?? '';
    }
}
