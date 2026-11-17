<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
#[Group('journeys')]
final class FirstRunWithoutAKeyJourneyTest extends JourneyTestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        if ('' !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    protected function journey(): string
    {
        return 'first run without a key';
    }

    public function testADemoInstallWithoutAKeyOpensOnTheFirstRunPageWithItsTwoChoices(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('start the demo without a key', $this->startTheDemoWithoutAKey(...));
        $this->step('open the dashboard on the first-run page', $this->openTheDashboard(...));
        $this->step('read the two choices', $this->readTheTwoChoices(...));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    // As the container entrypoint does on a start without a key: build, seed the repository alone, build again.
    private function startTheDemoWithoutAKey(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $environment = $this->demoEnvironment();
        $this->rebuildContainer($environment);

        $output = $this->console(['intakt:sample:seed', $environment['INTAKT_SAMPLE_REPOSITORY'], $environment['INTAKT_CONFIG']], $environment);
        $this->rebuildContainer($environment);
        $this->restartTheDashboard();

        self::assertStringContainsString('Built the sample repository', $output);
        self::assertStringNotContainsString('Wrote the sample project', $output);
    }

    private function openTheDashboard(): void
    {
        self::assertSame('/setup', $this->redirectOf('/'));
    }

    private function readTheTwoChoices(): void
    {
        $page = $this->visit('/setup');

        self::assertSame(
            ['Watch a recorded real review now', 'Paste one provider key or Hugging Face token and run the demo review'],
            $page->filter('#demo-choices h3')->each(static fn (Crawler $title): string => $title->text()),
        );
        self::assertSame('A replay runs only on the compose demo.', $page->filter('#choice-recorded .replay-where')->text());
        self::assertSame(
            'Paste the key or token in step 1, restart Intakt, then press "Start the first review" in step 3: it starts the demo review and opens its live page.',
            $page->filter('#choice-live .next')->text(),
        );
        self::assertCount(1, $page->filter('form.keep-provider-key'));
        self::assertCount(0, $page->filter('form.start-first-review'));
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
