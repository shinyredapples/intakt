<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Tests\Journeys\Support\JourneyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\Update;

#[CoversNothing]
#[Group('journeys')]
final class LiveReviewProgressJourneyTest extends JourneyTestCase
{
    // Not a credential, because the seed only checks that a key is present, and no step calls a provider.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';
    private const string ENDED_TEXT = 'Judged.';

    private string $directory = '';

    protected function tearDown(): void
    {
        if ('' !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    protected function journey(): string
    {
        return 'live review progress on the dashboard';
    }

    public function testTheDashboardShowsAReviewRoundsProgressLiveUntilTheJudgeHasRuled(): void
    {
        $this->step('create the database', $this->createTheDatabase(...));
        $this->step('seed the sample project', $this->seedTheSampleProject(...));
        $round = $this->step('start a round on the sample pull request', $this->startTheRound(...));
        $this->step('open the running round', fn () => $this->openTheRunningRound($round));
        $updates = $this->step('step the round until the judge has ruled', fn (): array => $this->stepTheRound($round));
        $this->step('read the updates in order on the round topic', fn () => $this->readTheUpdates($round, $updates));
        $this->step('open the judged round', fn () => $this->openTheJudgedRound($round));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    private function seedTheSampleProject(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $configuration = $this->directory.'/intakt.yaml';
        $environment = ['ANTHROPIC_API_KEY' => self::PLACEHOLDER_KEY, 'INTAKT_CONFIG' => $configuration];

        // Because the provider keys count only in a container built after they are set, as the container entrypoint does.
        $this->rebuildTheContainer($environment);
        $output = $this->console(['intakt:sample:seed', $this->directory.'/sample', $configuration], $environment);
        // Again, because the sample project is compiled into the container from the file the seed has just written, and the driver's kernel and the worker read that container.
        $this->rebuildTheContainer($environment);

        self::assertStringContainsString('Built the sample repository', $output);
    }

    /**
     * @param array<string,string> $environment
     */
    private function rebuildTheContainer(array $environment): void
    {
        $this->console(['cache:clear', '--no-warmup'], $environment);
        $this->console(['cache:warmup'], $environment);
    }

    private function startTheRound(): string
    {
        $round = $this->liveRoundDriver()->startOnSamplePullRequest($this->directory.'/sample');

        self::assertNotSame('', $round);

        return $round;
    }

    private function openTheRunningRound(string $round): void
    {
        // Without a live listener, because the instance has no hub secret to authorize one, so the page is the plain one and the updates are read from the recording hub.
        $page = $this->visit('/reviews/'.$round);

        self::assertSame('in progress', $page->filter('.state-running')->text());
        self::assertSame('0 of 2 reviewers finished.', $page->filter('#round-'.$round.'-state')->text());
    }

    /**
     * @return list<Update>
     */
    private function stepTheRound(string $round): array
    {
        return $this->liveRoundDriver()->stepUntilEnded($round);
    }

    /**
     * @param list<Update> $updates
     */
    private function readTheUpdates(string $round, array $updates): void
    {
        $topic = MercureRoundUpdates::topicOf($round);
        $ended = array_find_key($updates, static fn (Update $update): bool => str_contains($update->getData(), self::ENDED_TEXT));

        self::assertNotNull($ended, 'no update says the judge has ruled');
        self::assertGreaterThan(0, $ended, 'no progress update came before the judgement');

        foreach ($updates as $update) {
            self::assertContains($topic, $update->getTopics());
        }

        self::assertStringContainsString('reviewers finished.', $updates[0]->getData());
    }

    private function openTheJudgedRound(string $round): void
    {
        $page = $this->visit('/reviews/'.$round);

        self::assertStringContainsString(self::ENDED_TEXT, $page->filter('#round-'.$round.'-state')->text());
        self::assertSame('judged', mb_strtolower($page->filter('.state-judged')->text()));
    }
}
