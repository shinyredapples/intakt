<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

#[CoversClass(ListProjectsController::class)]
#[CoversClass(ListReviewsController::class)]
#[CoversClass(LivePageRendering::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
final class ShellLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private ?string $secretBefore = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'project list' => ['/projects'];
        yield 'review list' => ['/reviews'];
        yield 'review list of one project' => ['/reviews?project=alpha'];
    }

    #[DataProvider('pages')]
    public function testADashboardListPageListensOnceToTheShellTopicAsAPrivateStream(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', $path);

        $source = $crawler->filter('turbo-mercure-stream-source');
        self::assertCount(1, $source);
        self::assertNotNull($source->attr('private'));
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::SHELL), (string) $source->attr('src'));
        self::assertStringContainsString('<script type="importmap"', (string) $this->browser->getResponse()->getContent());
        self::assertNotEmpty($this->browser->getResponse()->headers->getCookies());
    }

    #[DataProvider('pages')]
    public function testTheStreamSourceSitsInTheMainColumnSoTheShellGridKeepsTheRailFirst(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', $path);

        self::assertCount(0, $crawler->filter('.shell > turbo-mercure-stream-source'));
        self::assertCount(1, $crawler->filter('.shell > .main > turbo-mercure-stream-source'));
    }

    #[DataProvider('pages')]
    public function testAListPageRendersItsRowsWhileNoHubRunsAtTheConfiguredAddress(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('main.content [data-project="alpha"]')->count());
    }

    #[DataProvider('pages')]
    public function testAListPageOpenedAfterAReviewPageStillGrantsEveryRoundTopicSoTheReviewTabKeepsItsUpdates(string $path): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->browser->request('GET', '/reviews/round-1');
        $reviewGrants = $this->grantedTopics();

        $this->browser->request('GET', $path);

        $expected = [['match' => DashboardTopics::SHELL], ['match' => 'review/rounds/:round', 'match_type' => 'urlpattern']];
        self::assertSame($expected, $this->grantedTopics());
        self::assertContains(['match' => 'review/rounds/:round', 'match_type' => 'urlpattern'], $reviewGrants);
    }

    public function testAnEventPublishedForTheShellTopicIsAddressedToWhatThePageListensTo(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $page = $this->browser->request('GET', '/projects');
        $published = [];
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'id';
        });

        new MercureLiveUpdates($hub, new NullLogger())->publish(new LiveEvent(DashboardTopics::SHELL, '<turbo-stream action="refresh"></turbo-stream>'));

        self::assertCount(1, $published);
        self::assertSame([DashboardTopics::SHELL], $published[0]->getTopics());
        self::assertTrue($published[0]->isPrivate());
        self::assertStringContainsString('match='.rawurlencode($published[0]->getTopics()[0]), (string) $page->filter('turbo-mercure-stream-source')->attr('src'));
    }

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

    /**
     * @return list<mixed>
     */
    private function grantedTopics(): array
    {
        foreach ($this->browser->getResponse()->headers->getCookies() as $cookie) {
            if ('mercure_access_token' !== $cookie->getName()) {
                continue;
            }

            $payload = explode('.', (string) $cookie->getValue())[1] ?? '';
            $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($claims);
            self::assertIsArray($claims['authorization_details'] ?? null);
            $topics = [];

            foreach ($claims['authorization_details'] as $detail) {
                self::assertIsArray($detail);
                self::assertIsArray($detail['topics'] ?? null);
                $topics = [...$topics, ...array_values($detail['topics'])];
            }

            return $topics;
        }

        self::fail('The page set no subscriber cookie.');
    }
}
