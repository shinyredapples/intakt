<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
#[Group('journeys')]
final class FirstInstallJourneyTest extends JourneyTestCase
{
    // Not a credential, because the seed only checks that a key is present, and no step calls a provider.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';
    private const string FIRST_RUN = '/setup';

    private string $directory = '';

    public function testAFreshInstallIsSetUpAndShowsItsEmptyDashboard(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('read the board headline of the fresh install', $this->readTheBoardHeadline(...));
        $this->step('follow the board link to the first-run page', $this->followTheBoardLinkToTheFirstRunPage(...));
        $this->step('open the projects page of the fresh install', $this->openProjectsOfAFreshInstall(...));
        $this->step('seed the sample project', $this->seedTheSampleProject(...));
        $this->step('seed it again and keep what exists', $this->seedAgain(...));
        $this->step('open the reviews page', $this->openReviews(...));
        $review = $this->step('start the first review from the first-run page', $this->startTheFirstReview(...));
        $this->step('open the review page the start redirected to', fn () => $this->openTheFirstReview($review));
        $this->step('find the first-run page on the projects page', $this->findTheFirstRunPageOnProjects(...));
    }

    protected function tearDown(): void
    {
        if ('' !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    protected function journey(): string
    {
        return 'first install and setup';
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    private function readTheBoardHeadline(): void
    {
        self::assertSame('Nothing is running yet. Start the first review.', $this->visit('/')->filter('#board-headline')->text());
    }

    private function followTheBoardLinkToTheFirstRunPage(): void
    {
        $link = $this->visit('/')->filter('.page-head .actions a.btn.primary');

        self::assertSame('Set up Intakt', $link->text());
        self::assertSame(self::FIRST_RUN, $link->attr('href'));
        self::assertCount(3, $this->visit(self::FIRST_RUN)->filter('.own-steps > li'));
    }

    private function openProjectsOfAFreshInstall(): void
    {
        self::assertStringContainsString('No project has started a review yet.', $this->visit('/projects')->text());
    }

    private function seedTheSampleProject(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $output = $this->seed();

        self::assertStringContainsString('Wrote the sample project', $output);
        self::assertStringContainsString('Built the sample repository', $output);
    }

    private function seedAgain(): void
    {
        $output = $this->seed();

        self::assertStringContainsString('Kept the project configuration', $output);
        self::assertStringContainsString('Kept the sample repository', $output);
    }

    private function openReviews(): void
    {
        $page = $this->visit('/reviews')->text();

        self::assertStringContainsString('Reviews', $page);
        self::assertStringContainsString('No review has started yet.', $page);
    }

    // Through the journey's own kernel, because only it has the sample repository the start button needs; it writes to the instance's ledger, so the instance shows the round; the container is rebuilt first, because the sample repository is read when it is built.
    private function startTheFirstReview(): string
    {
        $environment = [...$this->environment(), 'INTAKT_SAMPLE_REPOSITORY' => $this->directory.'/sample'];
        $this->rebuildContainer($environment);
        $browser = $this->dashboardWith($environment);
        $page = $browser->request('GET', self::FIRST_RUN);
        $page = $browser->submit($page->selectButton('Start the first review')->form());

        self::assertSame('Started round 1.', $page->filter('.toast')->text());

        return (string) parse_url($browser->getRequest()->getUri(), PHP_URL_PATH);
    }

    private function openTheFirstReview(string $review): void
    {
        self::assertStringStartsWith('/reviews/', $review);
        self::assertStringContainsString(SampleChange::CHANGE, $this->visit($review)->filter('.page-head')->text());
    }

    private function findTheFirstRunPageOnProjects(): void
    {
        self::assertSame(self::FIRST_RUN, $this->visit('/projects')->filter('.page-head .actions a')->attr('href'));
        self::assertCount(0, $this->visit('/')->filter('a[href="'.self::FIRST_RUN.'"]'));
    }

    private function seed(): string
    {
        $environment = $this->environment();
        $this->rebuildContainer($environment);

        return $this->console(['intakt:sample:seed', $this->directory.'/sample', $environment['INTAKT_CONFIG']], $environment);
    }

    /**
     * @return array{ANTHROPIC_API_KEY: string, INTAKT_CONFIG: string}
     */
    private function environment(): array
    {
        return ['ANTHROPIC_API_KEY' => self::PLACEHOLDER_KEY, 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml'];
    }
}
