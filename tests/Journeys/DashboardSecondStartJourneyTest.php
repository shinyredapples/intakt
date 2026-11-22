<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
#[Group('journeys')]
final class DashboardSecondStartJourneyTest extends JourneyTestCase
{
    // Not a credential, because the seed only checks that a key is present, and no step calls a provider.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';
    private const string FIRST_RUN = '/setup';
    private const string ENDED_TEXT = 'Checked.';
    private const string STARTED = 'Started round 2.';
    private const string REFUSED = 'Round 2 was asked for, but the next round of this change is round 3.';
    // Anchored at both ends, because the ids of a round's parts go on after the round id and only the round's own block counts as a round.
    private const string ROUND_BLOCK = '/^round-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/';

    private string $directory = '';

    protected function tearDown(): void
    {
        if ('' !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    protected function journey(): string
    {
        return 'second start from the dashboard';
    }

    public function testASecondPressOfTheStartOnAJudgedReviewPageIsRefusedAndStartsNoRound(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('seed the sample project', $this->seedTheSampleProject(...));
        $review = $this->step('start the first review from the first-run page', $this->startTheFirstReview(...));
        $this->step('run the round until the judge has ruled', fn () => $this->runTheRound($review));
        $dashboard = $this->dashboardWith($this->environment());
        $form = $this->step('press the start of round 2 on the judged review page', fn (): Form => $this->pressTheStartOfRoundTwo($dashboard, $review));
        $this->step('press the same start again and read the refusal', fn () => $this->pressTheSameStartAgain($dashboard, $form, $review));
        $this->step('read that the instance holds two rounds and offers round 3', fn () => $this->readTheRoundsOfTheInstance($review));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    // Rebuilt before the seed because the seed reads the key through the container, and after it because the sample project and the sample repository are read when the container is built.
    private function seedTheSampleProject(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $environment = $this->environment();
        $this->rebuildContainer($environment);
        $output = $this->console(['intakt:sample:seed', $environment['INTAKT_SAMPLE_REPOSITORY'], $environment['INTAKT_CONFIG']], $environment);
        $this->rebuildContainer($environment);

        self::assertStringContainsString('Wrote the sample project', $output);
    }

    // Through the journey's own kernel, because the instance's server queues on the test environment's in-memory transport, which no worker reads; this kernel queues on the shared one the round driver consumes, and writes to the instance's ledger.
    private function startTheFirstReview(): string
    {
        $dashboard = $this->dashboardWith($this->environment());
        $page = $dashboard->submit($dashboard->request('GET', self::FIRST_RUN)->selectButton('Start the first review')->form());
        $review = (string) parse_url($dashboard->getRequest()->getUri(), PHP_URL_PATH);

        self::assertSame('Started round 1.', $page->filter('.toast')->text());
        self::assertMatchesRegularExpression('#^/reviews/[^/]+$#', $review);

        return $review;
    }

    private function runTheRound(string $review): void
    {
        $round = substr($review, strlen('/reviews/'));
        $this->liveRoundDriver()->stepUntilEnded($round);

        self::assertStringContainsString(self::ENDED_TEXT, $this->visit($review)->filter('#round-'.$round.'-state')->text());
    }

    private function pressTheStartOfRoundTwo(KernelBrowser $dashboard, string $review): Form
    {
        $form = $dashboard->request('GET', $review)->filter('form.start-review')->form();

        self::assertSame('2', $form->getPhpValues()['number'] ?? null);
        self::assertSame(1, $this->roundsOn($review));
        self::assertSame($review, $this->redirectOfPressing($dashboard, $form));
        self::assertSame(self::STARTED, $dashboard->followRedirect()->filter('.toast')->text());
        self::assertSame(2, $this->roundsOn($review));

        return $form;
    }

    // The form of the page as it was before the first press, so its number is the stale one a double submit sends.
    private function pressTheSameStartAgain(KernelBrowser $dashboard, Form $form, string $review): void
    {
        self::assertSame($review, $this->redirectOfPressing($dashboard, $form));
        self::assertSame(self::REFUSED, $dashboard->followRedirect()->filter('.flash-error')->text());
        self::assertSame(2, $this->roundsOn($review));
    }

    // Not followed here, because both presses answer with the same redirect and only the line on the page the caller then follows to tells them apart.
    private function redirectOfPressing(KernelBrowser $dashboard, Form $form): string
    {
        $dashboard->followRedirects(false);
        $dashboard->submit($form);
        $response = $dashboard->getResponse();

        self::assertSame(303, $response->getStatusCode());

        return (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
    }

    // Read on the instance's own server, so the rounds are the ones its database holds and not this journey's kernel's.
    private function readTheRoundsOfTheInstance(string $review): void
    {
        $page = $this->visit($review);

        self::assertSame('3', $page->filter('form.start-review input[name="number"]')->attr('value'));
        self::assertSame('Start round 3', $page->filter('form.start-review button')->text());
    }

    // Counted on the instance's own server, so the rounds are the ones its database holds.
    private function roundsOn(string $review): int
    {
        $ids = $this->visit($review)->filter('[id^="round-"]')->each(static fn (Crawler $part): string => (string) $part->attr('id'));

        return count(array_filter($ids, static fn (string $id): bool => 1 === preg_match(self::ROUND_BLOCK, $id)));
    }

    /**
     * @return array{ANTHROPIC_API_KEY: string, INTAKT_CONFIG: string, INTAKT_SAMPLE_REPOSITORY: string}
     */
    private function environment(): array
    {
        return [
            'ANTHROPIC_API_KEY' => self::PLACEHOLDER_KEY,
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'INTAKT_SAMPLE_REPOSITORY' => $this->directory.'/sample',
        ];
    }
}
