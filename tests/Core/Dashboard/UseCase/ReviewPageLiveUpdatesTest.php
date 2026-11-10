<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(ProjectMode::class)]
#[UsesClass(RoundProgress::class)]
final class ReviewPageLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private ?string $secretBefore = null;

    #[Override]
    protected function setUp(): void
    {
        $before = $_SERVER['MERCURE_JWT_SECRET'] ?? null;
        $this->secretBefore = is_string($before) ? $before : null;
        // A fresh random key, because a committed one would be a secret; the page only signs a listener's token with it.
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = bin2hex(random_bytes(16));
        parent::setUp();
        $this->givenTheDownloadedScriptsExist();
    }

    #[Override]
    protected function tearDown(): void
    {
        $secret = $this->secretBefore ?? '';
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = $secret;
        $this->removeTheStandIns();
        parent::tearDown();
    }

    public function testTheReviewPageListensToThePrivateTopicOfARunningRound(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $source = $crawler->filter('turbo-mercure-stream-source');
        self::assertCount(1, $source);
        self::assertNotNull($source->attr('private'));
        self::assertStringContainsString('match=review%2Frounds%2Fround-1', (string) $source->attr('src'));
        self::assertStringContainsString('<script type="importmap"', (string) $this->browser->getResponse()->getContent());
        self::assertNotEmpty($this->browser->getResponse()->headers->getCookies());
    }

    public function testAJudgedRoundDoesNotListenBecauseNothingMoreWillBePublished(): void
    {
        $this->givenARunningRound();
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringNotContainsString('review%2Frounds', (string) $crawler->filter('turbo-mercure-stream-source')->attr('src'));
        self::assertSame('Judged.', $crawler->filter('#round-round-1-state')->text());
    }

    public function testThePageHoldsTheElementsTheStreamsReplaceAndAppendTo(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $streams = [
            $this->stream('tool_called', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'tool' => 'read_file']),
            $this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking']),
            $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false)]),
        ];

        foreach ($streams as $stream) {
            $target = new Crawler($stream)->filter('turbo-stream[target]')->attr('target');
            self::assertNotNull($target);
            self::assertCount(1, $page->filter('#'.$target), $target);
        }
    }

    public function testOneListenerAuthorizesEveryUnfinishedRoundOfThePage(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $crawler->filter('turbo-mercure-stream-source'));
        self::assertSame(['dashboard/shell', 'review/rounds/round-1', 'review/rounds/round-2'], $this->subscribedTopics());
    }

    public function testAReviewerThatStartsAfterThePageLoadedGetsItsPlaceFromItsFirstUpdate(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');
        $this->ledger->startReviewer('round-1', 'correctness');

        $placed = new Crawler($this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking']))->filter('turbo-stream[targets]');

        self::assertSame('#round-round-1-live:not(:has(#reviewer-round-1-correctness-text))', $placed->attr('targets'));
        self::assertCount(0, $page->filter('#reviewer-round-1-correctness-text'));
        $updated = $this->appended($page->filter('#round-round-1-live'), $placed->filter('template')->html());
        self::assertCount(1, $updated->filter('#round-round-1-live #reviewer-round-1-correctness-text'));
        self::assertCount(1, $updated->filter('#round-round-1-live #reviewer-round-1-correctness-activity'));
    }

    public function testAReviewerThePageAlreadyShowsKeepsItsPlaceSoItsTextStays(): void
    {
        $this->givenARunningRound();

        $page = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $page->filter('#round-round-1-live #reviewer-round-1-correctness-text'));
    }

    // Nothing listens at MERCURE_URL in the test environment, so this is the page of an install whose hub is stopped.
    public function testThePageRendersFullyWhileTheHubIsStopped(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertResponseIsSuccessful();
        self::assertSame('0 of 2 reviewers finished.', $crawler->filter('#round-round-1-state')->text());
        self::assertSame(['correctness'], $crawler->filter('table.reviewers tbody td:first-child')->each(static fn (Crawler $td): string => $td->text()));
    }

    public function testTheConnectionControllerHoldsBackTheStopButtonAndNoButtonOfTheShell(): void
    {
        $this->givenARunningRound();
        $actions = $this->actionsTheConnectionControllerHolds();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $held = $crawler->filter('.shell[data-controller="connection"]')->filter($actions)->each(static fn (Crawler $button): string => $button->text());
        self::assertSame(['Stop this reviewer'], $held);
    }

    private function actionsTheConnectionControllerHolds(): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 4).'/assets/controllers/connection_controller.js');
        self::assertSame(1, preg_match("/const ACTIONS = '(?<selector>[^']+)';/", $script, $found));

        return $found['selector'] ?? '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stream(string $name, array $context): string
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render(sprintf('dashboard/stream/%s.stream.html.twig', $name), $context);
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests.
    private function appended(Crawler $target, string $template): Crawler
    {
        $node = $target->getNode(0);
        self::assertNotNull($node);
        $document = $node->ownerDocument;
        self::assertNotNull($document);

        foreach (new Crawler('<div>'.$template.'</div>')->filter('body > div > *') as $child) {
            $node->appendChild($document->importNode($child, true));
        }

        return new Crawler($document);
    }

    /**
     * @return list<string>
     */
    private function subscribedTopics(): array
    {
        foreach ($this->browser->getResponse()->headers->getCookies() as $cookie) {
            if ('mercure_access_token' !== $cookie->getName()) {
                continue;
            }

            $payload = explode('.', (string) $cookie->getValue())[1] ?? '';
            $claims = (string) base64_decode(strtr($payload, '-_', '+/'), true);
            preg_match_all('#(?:review/rounds|dashboard)/[\w-]+#', stripslashes($claims), $topics);

            return array_values(array_unique($topics[0]));
        }

        self::fail('The page set no subscriber cookie.');
    }

    private function givenARunningRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
    }
}
