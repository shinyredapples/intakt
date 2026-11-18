<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use Intakt\Core\Watcher\Contract\FailingMains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ShowBoardController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(FailingMains::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Headline::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(RefuseForeignHost::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(SampleChangeSource::class)]
final class BoardPhoneLandingTest extends DashboardPageTestCase
{
    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    private const string ANDROID_FIREFOX = 'Mozilla/5.0 (Android 15; Mobile; rv:140.0) Gecko/140.0 Firefox/140.0';
    private const string DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('phones')]
    public function testAPhoneOpeningTheAddressLandsOnWaitingOnYou(array $headers): void
    {
        $this->browser->request('GET', '/', server: $headers);

        self::assertResponseRedirects('/waiting', Response::HTTP_SEE_OTHER);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function phones(): iterable
    {
        yield 'Safari on an iPhone, which sends no client hint' => [['HTTP_USER_AGENT' => self::IPHONE]];
        yield 'Firefox on Android, which sends no client hint' => [['HTTP_USER_AGENT' => self::ANDROID_FIREFOX]];
        yield 'Chromium on a phone, by its client hint' => [['HTTP_SEC_CH_UA_MOBILE' => '?1']];
        yield 'a link from another site' => [['HTTP_USER_AGENT' => self::IPHONE, 'HTTP_REFERER' => 'https://mail.example/inbox']];
        yield 'a link from inside the dashboard without the board flag' => [['HTTP_USER_AGENT' => self::IPHONE, 'HTTP_REFERER' => 'http://localhost:7780/waiting']];
    }

    public function testAPhoneFollowingTheBoardEntryStillReachesTheBoard(): void
    {
        $page = $this->browser->request('GET', '/?board=1', server: ['HTTP_USER_AGENT' => self::IPHONE, 'HTTP_SEC_CH_UA_MOBILE' => '?1']);

        self::assertResponseIsSuccessful();
        self::assertSame('page', $page->filter('.rail a.nav[data-k="board"]')->attr('aria-current'));
    }

    public function testTheBoardEntryOfTheRailAndBottomBarCarriesTheBoardFlag(): void
    {
        $page = $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::DESKTOP]);

        self::assertSame('/?board=1', $page->filter('.rail a.nav[data-k="board"]')->attr('href'));
    }

    public function testADesktopOpeningTheAddressGetsTheBoard(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::DESKTOP, 'HTTP_SEC_CH_UA_MOBILE' => '?0']);

        self::assertResponseIsSuccessful();
    }

    public function testAPhoneOnADemoInstallWithoutAReviewStillOpensTheFirstRunPage(): void
    {
        $container = self::getContainer();
        $container->set(SampleChangeSource::class, new SampleChangeSource($container->get(TierResolver::class), '/repos/sample'));

        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::IPHONE]);

        self::assertResponseRedirects('/setup', Response::HTTP_SEE_OTHER);
    }

    public function testTheBoardVariesByTheHeadersThatChooseTheLanding(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::DESKTOP]);

        $vary = $this->browser->getResponse()->getVary();
        self::assertContains('Sec-CH-UA-Mobile', $vary);
        self::assertContains('User-Agent', $vary);
    }

    public function testThePhoneRedirectVariesByTheHeadersThatChooseIt(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::IPHONE]);

        self::assertContains('User-Agent', $this->browser->getResponse()->getVary());
    }
}
