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

    public function testAPastedTokenRunsTheDemoReviewAndOpensItsLiveReviewPage(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('start the demo without a key', $this->startTheDemoWithoutAKey(...));
        $this->step('open the dashboard on the first-run page', $this->openTheDashboard(...));
        $this->step('paste a Hugging Face token', $this->pasteAToken(...));
        $this->step('restart with the kept token', $this->restartWithTheKeptToken(...));
        $this->step('press the start of the demo review', $this->pressTheStart(...));
        $this->step('open the live review page', $this->openTheLiveReviewPage(...));
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

    private function pressTheStart(): void
    {
        $form = $this->visit('/setup')->filter('form.start-first-review')->form();

        $this->review = $this->redirectOfSubmit($form);

        self::assertMatchesRegularExpression('#^/reviews/[^/]+$#', $this->review);
    }

    private function openTheLiveReviewPage(): void
    {
        $round = substr($this->review, strlen('/reviews/'));

        $page = $this->visit($this->review);

        self::assertCount(1, $page->filter('#round-'.$round.'-state'));
        self::assertStringContainsString('sample-change', $page->filter('h1')->text());
    }

    // Build, seed, build again, as the container entrypoint does on every start.
    private function startAsTheEntrypointDoes(): string
    {
        $environment = [
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'INTAKT_SAMPLE_REPOSITORY' => $this->directory.'/sample',
            'INTAKT_PROVIDER_KEY_DIR' => $this->directory.'/kept',
        ];
        $this->rebuildContainer($environment);
        $output = $this->console(['intakt:sample:seed', $environment['INTAKT_SAMPLE_REPOSITORY'], $environment['INTAKT_CONFIG']], $environment);
        $this->rebuildContainer($environment);
        $this->restartTheDashboard();

        return $output;
    }
}
