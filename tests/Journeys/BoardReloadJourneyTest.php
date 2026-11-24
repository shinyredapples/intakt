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
final class BoardReloadJourneyTest extends JourneyTestCase
{
    public function testTheBoardShowsTheRefusedAdminApiReasonOnEveryLoadThroughOneDashboardBrowser(): void
    {
        $this->step('create the database', fn () => $this->console(['doctrine:migrations:migrate']));
        $dashboard = $this->dashboardWith([]);
        $this->step('open the board', fn () => $this->readTheRefusalOnTheBoard($dashboard));
        // The second request runs on the kernel KernelBrowser rebooted, which is the case the first load cannot show.
        $this->step('reload the board', fn () => $this->readTheRefusalOnTheBoard($dashboard));
    }

    protected function journey(): string
    {
        return 'board reload';
    }

    private function readTheRefusalOnTheBoard(KernelBrowser $dashboard): void
    {
        $board = $dashboard->request('GET', '/?board=1');

        self::assertSame(200, $dashboard->getResponse()->getStatusCode());
        self::assertSame(array_fill(0, 3, $this->refusedAdminApiReason()), $board->filter('#infrastructure .infra-reason')->each(
            static fn (Crawler $reason): string => trim($reason->text(), '()'),
        ));
    }
}
