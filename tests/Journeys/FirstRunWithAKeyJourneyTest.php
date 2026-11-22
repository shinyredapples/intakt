<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
#[Group('journeys')]
final class FirstRunWithAKeyJourneyTest extends JourneyTestCase
{
    // Built from parts, so no key-shaped literal sits in the repository; the test environment's key check accepts it without a call.
    private const string TOKEN_BODY = 'journeyplaceholder';
    private const string ENDED_TEXT = 'Checked.';
    private const string OUTCOME = '/^Outcome: \S+, decided by the quorum policy \S+ over all 2 reviewers\./';

    private string $directory = '';

    private string $review = '';

    protected function tearDown(): void
    {
        if ('' !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    protected function journey(): string
    {
        return 'first run with a key';
    }

    public function testAPastedTokenRunsTheDemoReviewWhoseLiveReviewPageShowsTheVerdict(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('start the demo without a key', $this->startTheDemoWithoutAKey(...));
        $this->step('open the dashboard on the first-run page', $this->openTheDashboard(...));
        $this->step('paste a Hugging Face token', $this->pasteAToken(...));
        $this->step('restart with the kept token', $this->restartWithTheKeptToken(...));
        $this->step('press the start of the demo review', $this->pressTheStart(...));
        $this->step('open the live review page', $this->openTheLiveReviewPage(...));
        $this->step('run the round until the judge has ruled', $this->runTheRound(...));
        $this->step('read the verdict on the review page', $this->readTheVerdict(...));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    private function startTheDemoWithoutAKey(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));

        self::assertStringNotContainsString('Wrote the sample project', $this->startAsTheEntrypointDoes());
    }

    private function openTheDashboard(): void
    {
        self::assertSame('/setup', $this->redirectOf('/'));
    }

    private function pasteAToken(): void
    {
        $form = $this->visit('/setup')->filter('form.keep-provider-key')->form(['key' => 'hf_'.self::TOKEN_BODY]);

        self::assertSame('/setup', $this->redirectOfSubmit($form));
        self::assertStringContainsString('is kept. Restart Intakt to use it.', $this->visit('/setup')->filter('#step-providers .lead')->text());
    }

    private function restartWithTheKeptToken(): void
    {
        self::assertStringContainsString('Wrote the sample project', $this->startAsTheEntrypointDoes());
    }

    // Through the journey's own kernel, because the instance's server queues on the test environment's in-memory transport, which no worker reads; this kernel queues on the shared one the round driver consumes, and writes to the instance's ledger.
    private function pressTheStart(): void
    {
        $browser = $this->dashboardWith($this->demoEnvironment());
        $browser->followRedirects(false);
        $browser->submit($browser->request('GET', '/setup')->filter('form.start-first-review')->form());
        $response = $browser->getResponse();

        self::assertSame(303, $response->getStatusCode());
        $this->review = (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
        self::assertMatchesRegularExpression('#^/reviews/[^/]+$#', $this->review);
        self::assertSame('Started round 1.', $browser->followRedirect()->filter('.toast')->text());
    }

    private function openTheLiveReviewPage(): void
    {
        $page = $this->visit($this->review);

        self::assertCount(1, $page->filter('#round-'.$this->round().'-state'));
        self::assertStringContainsString('sample-change', $page->filter('h1')->text());
    }

    private function runTheRound(): void
    {
        self::assertNotSame([], $this->liveRoundDriver()->stepUntilEnded($this->round()));
    }

    private function readTheVerdict(): void
    {
        $round = $this->round();
        $page = $this->visit($this->review);

        self::assertStringContainsString(self::ENDED_TEXT, $page->filter('#round-'.$round.'-state')->text());
        self::assertSame('checked', mb_strtolower($page->filter('.state-judged')->text()));
        self::assertMatchesRegularExpression(self::OUTCOME, $page->filter('#round-'.$round.'-outcome')->text());
    }

    private function round(): string
    {
        return substr($this->review, strlen('/reviews/'));
    }

    // Built before the seed because the seed reads the kept key through the container, and after it because the sample project lives in the compiled container.
    private function startAsTheEntrypointDoes(): string
    {
        $environment = $this->demoEnvironment();
        $this->rebuildContainer($environment);
        $output = $this->console(['intakt:sample:seed', $environment['INTAKT_SAMPLE_REPOSITORY'], $environment['INTAKT_CONFIG']], $environment);
        $this->rebuildContainer($environment);
        $this->restartTheDashboard();

        return $output;
    }

    /**
     * @return array{INTAKT_CONFIG: string, INTAKT_SAMPLE_REPOSITORY: string, INTAKT_PROVIDER_KEY_DIR: string}
     */
    private function demoEnvironment(): array
    {
        return [
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'INTAKT_SAMPLE_REPOSITORY' => $this->directory.'/sample',
            'INTAKT_PROVIDER_KEY_DIR' => $this->directory.'/kept',
        ];
    }
}
