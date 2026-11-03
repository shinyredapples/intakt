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
    private const array VENDOR_ASSETS = ['@hotwired/stimulus/stimulus.index.js', '@hotwired/turbo/turbo.index.js'];

    private ?string $secretBefore = null;

    /** @var list<string> */
    private array $placeholders = [];

    #[Override]
    protected function setUp(): void
    {
        $before = $_SERVER['MERCURE_JWT_SECRET'] ?? null;
        $this->secretBefore = is_string($before) ? $before : null;
        // A fresh random key, because a committed one would be a secret; the page only signs a listener's token with it.
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = bin2hex(random_bytes(16));
        $this->givenTheDownloadedScriptsExist();
        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        $secret = $this->secretBefore ?? '';
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = $secret;
        array_map(unlink(...), $this->placeholders);
        parent::tearDown();
    }

    // The scripts are downloaded by importmap:install, which needs the network; the page only maps their paths, so empty stand-ins make it listen.
    private function givenTheDownloadedScriptsExist(): void
    {
        foreach (self::VENDOR_ASSETS as $asset) {
            $path = dirname(__DIR__, 4).'/assets/vendor/'.$asset;
            if (is_file($path)) {
                continue;
            }

            is_dir(dirname($path)) || mkdir(dirname($path), 0o777, true);
            file_put_contents($path, '');
            $this->placeholders[] = $path;
        }
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

        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertSame('Judged.', $crawler->filter('#round-round-1-state')->text());
    }

    public function testThePageHoldsTheElementsTheStreamsReplaceAndAppendTo(): void
    {
        $this->givenARunningRound();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $streams = [
            $twig->render('dashboard/stream/tool_called.stream.html.twig', ['reviewer' => 'round-1-correctness', 'tool' => 'read_file']),
            $twig->render('dashboard/stream/text_streamed.stream.html.twig', ['reviewer' => 'round-1-correctness', 'text' => 'Looking']),
            $twig->render('dashboard/stream/round_changed.stream.html.twig', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false)]),
        ];

        foreach ($streams as $stream) {
            $target = new Crawler($stream)->filter('turbo-stream')->attr('target');
            self::assertNotNull($target);
            self::assertCount(1, $page->filter('#'.$target), $target);
        }
    }

    private function givenARunningRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
    }
}
