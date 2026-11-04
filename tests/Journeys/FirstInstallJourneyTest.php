<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
#[Group('journeys')]
final class FirstInstallJourneyTest extends JourneyTestCase
{
    // Not a credential: the seed only checks that a key is present, and no step calls a provider.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';

    private string $directory = '';

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

    public function testAFreshInstallIsSetUpAndShowsItsEmptyDashboard(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('open the projects page of the fresh install', $this->openProjectsOfAFreshInstall(...));
        $this->step('seed the sample project', $this->seedTheSampleProject(...));
        $this->step('seed it again and keep what exists', $this->seedAgain(...));
        $this->step('open the reviews page', $this->openReviews(...));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
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

    private function seed(): string
    {
        $configuration = $this->directory.'/intakt.yaml';
        $environment = ['ANTHROPIC_API_KEY' => self::PLACEHOLDER_KEY, 'INTAKT_CONFIG' => $configuration];

        // As the container entrypoint does: the provider keys count only in a container built after they are set.
        $this->console(['cache:clear', '--no-warmup'], $environment);
        $this->console(['cache:warmup'], $environment);

        return $this->console(['intakt:sample:seed', $this->directory.'/sample', $configuration], $environment);
    }
}
