<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ShowBoardController::class)]
final class BoardPhoneLandingTest extends DashboardPageTestCase
{
    use AssertsLoggedErrors;

    private const string IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const string FIREFOX_ON_ANDROID = 'Mozilla/5.0 (Android 15; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0';

    private const string DESKTOP_CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const string VARY = 'Sec-CH-UA-Mobile, User-Agent';

    public function testAnIphoneSafariUserAgentWithoutClientHintsIsRedirectedToWaitingOnYouWith303(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::IPHONE_SAFARI]);

        self::assertResponseRedirects('/waiting', Response::HTTP_SEE_OTHER);
        self::assertResponseHeaderSame('Vary', self::VARY);
    }

    public function testFirefoxOnAndroidIsRedirectedToWaitingOnYou(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::FIREFOX_ON_ANDROID]);

        self::assertResponseRedirects('/waiting', Response::HTTP_SEE_OTHER);
    }

    public function testARequestWithTheMobileClientHintIsRedirectedToWaitingOnYou(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::DESKTOP_CHROME, 'HTTP_SEC_CH_UA_MOBILE' => '?1']);

        self::assertResponseRedirects('/waiting', Response::HTTP_SEE_OTHER);
    }

    public function testADesktopClientHintWithADesktopUserAgentSeesTheBoardWithTheVaryHeader(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::DESKTOP_CHROME, 'HTTP_SEC_CH_UA_MOBILE' => '?0']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Vary', self::VARY);
    }

    public function testTheBoardLinkWithTheFlagIsNotRedirectedOnAPhone(): void
    {
        $page = $this->browser->request('GET', '/?board=1', server: ['HTTP_USER_AGENT' => self::IPHONE_SAFARI]);

        self::assertResponseIsSuccessful();
        self::assertSame('Board', $page->filter('.page-head h1')->text());
    }

    public function testAnOutsideLinkWithAReferrerStillLandsOnWaitingOnYou(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::IPHONE_SAFARI, 'HTTP_REFERER' => 'https://example.com/chat']);

        self::assertResponseRedirects('/waiting', Response::HTTP_SEE_OTHER);
    }

    public function testOnADemoInstallWithNoReviewAPhoneReachesSetupAndTheRedirectSendsVary(): void
    {
        $container = self::getContainer();
        $container->set(SampleChangeSource::class, new SampleChangeSource($container->get(TierResolver::class), '/repos/sample'));

        $this->browser->request('GET', '/', server: ['HTTP_USER_AGENT' => self::IPHONE_SAFARI]);

        self::assertResponseRedirects('/setup', Response::HTTP_SEE_OTHER);
        self::assertResponseHeaderSame('Vary', self::VARY);
    }

    public function testFollowingBackToTheBoardFromA404OnAPhoneReachesTheBoardWithoutARedirect(): void
    {
        $this->browser->disableReboot();
        $this->browser->setServerParameter('HTTP_USER_AGENT', self::IPHONE_SAFARI);
        $missing = $this->browser->request('GET', '/does-not-exist');
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780/does-not-exist""', NotFoundHttpException::class));

        $page = $this->browser->click($missing->selectLink('Back to the board')->link());

        self::assertResponseIsSuccessful();
        self::assertSame('Board', $page->filter('.page-head h1')->text());
    }

    public function testTheRailsBoardEntryCarriesTheBoardFlagAndTheHomeLinksDoNot(): void
    {
        $page = $this->browser->request('GET', '/reviews');

        self::assertSame('/?board=1', $page->filter('.rail a.nav[data-k="board"]')->attr('href'));
        self::assertSame(['/', '/'], $page->filter('a[aria-label="Intakt home"]')->each(static fn (Crawler $link): ?string => $link->attr('href')));
    }
}
