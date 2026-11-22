<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
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

    public function testADemoInstallWithoutAKeyOpensOnTheFirstRunPageWithTheRecordedReviewOffer(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('start the demo without a key', $this->startTheDemoWithoutAKey(...));
        $this->step('open the dashboard on the first-run page', $this->openTheDashboard(...));
        $this->step('read the recorded review offer', $this->readTheRecordedReviewOffer(...));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    // Built again after the seed, because the dashboard reads the sample repository from the compiled container, as after the entrypoint's start.
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

    private function readTheRecordedReviewOffer(): void
    {
        $page = $this->visit('/setup');

        self::assertSame('See a real review', $page->filter('#see-review h1')->text());
        self::assertSame('This install has no recorded review. Add a key below to run one live.', $page->filter('#see-review .next')->text());
        self::assertSame('missing', $page->filter('#step-providers')->attr('data-state'));
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
