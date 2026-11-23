<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

#[CoversNothing]
#[Group('journeys')]
final class JudgesPageAfterTheDemoRunJourneyTest extends JourneyTestCase
{
    private const string ROOT = __DIR__.'/../..';
    private const string SETUP_ADDRESS = 'http://localhost:7781/setup';
    private const string DEMO_COMMAND = '`docker compose exec intakt php bin/console intakt:demo`';
    // Far above the recording's length in seconds, so the replay writes its rows without waiting between them.
    private const string REPLAY_FACTOR = '100000';

    public function testAfterTheDemoRunTheJudgesPageLeadsToTheKeyFormAndTheDemoCommandInsteadOfAButtonThatIsGone(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('play the shipped recording', $this->playTheShippedRecording(...));
        $dashboard = $this->dashboardWith($this->environment());
        $this->step('open the dashboard on the board', fn () => $this->openTheDashboardOnTheBoard($dashboard));
        $this->step('find the key form but no start button on the setup page', fn () => $this->findTheKeyFormButNoStartButton($dashboard));
        $this->step('read the route the judges page gives', $this->readTheRouteTheJudgesPageGives(...));
    }

    protected function journey(): string
    {
        return 'judges page after the demo run';
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    // Removed first, so a run left by an earlier journey on this instance is not replayed twice.
    private function playTheShippedRecording(): void
    {
        $this->console(['intakt:demo:remove'], $this->environment());
        $this->console(['intakt:demo:create'], $this->environment());
        $this->console(['intakt:demo:replay', self::ROOT.'/fixtures/demo/sample-change-review.json', '--factor='.self::REPLAY_FACTOR], $this->environment());
    }

    /**
     * @return array{INTAKT_COMPOSE_SERVICE: string}
     */
    private function environment(): array
    {
        return ['INTAKT_COMPOSE_SERVICE' => 'intakt'];
    }

    private function openTheDashboardOnTheBoard(KernelBrowser $dashboard): void
    {
        $page = $dashboard->request('GET', '/');

        self::assertSame(200, $dashboard->getResponse()->getStatusCode());
        // The journey's own reading, so the board never asked an admin API that happens to listen on this machine.
        self::assertSame(['not read in a journey', 'not read in a journey', 'not read in a journey'], $page->filter('#infrastructure .infra-reason')->each(
            static fn (Crawler $reason): string => trim($reason->text(), '()'),
        ));
    }

    private function findTheKeyFormButNoStartButton(KernelBrowser $dashboard): void
    {
        $page = $dashboard->request('GET', '/setup');

        self::assertCount(1, $page->filter('form.keep-provider-key'));
        self::assertSame('Open the review', $page->filter('#step-first-review a.btn')->text());
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    private function readTheRouteTheJudgesPageGives(): void
    {
        $page = (string) file_get_contents(self::ROOT.'/docs/judges.md');
        $liveReview = $this->between($page, '## b.', '## c.');
        $agent = $this->between($page, '## c.', '## Where to look');

        self::assertStringContainsString('1. Open '.self::SETUP_ADDRESS, $liveReview);
        self::assertStringContainsString('3. Reload '.self::SETUP_ADDRESS, $liveReview);
        self::assertStringContainsString('**If you played path a:** card 3 shows **Open the review**', $liveReview);
        self::assertStringContainsString('Run '.self::DEMO_COMMAND.' instead.', $liveReview);
        self::assertStringContainsString('(the first-run page shows no address or command)', $agent);
    }

    private function between(string $page, string $from, string $to): string
    {
        $start = (int) strpos($page, $from);

        return substr($page, $start, (int) strpos($page, $to) - $start);
    }
}
