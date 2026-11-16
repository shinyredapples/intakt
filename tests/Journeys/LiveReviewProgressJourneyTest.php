<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Tests\Journeys\Support\JourneyTestCase;
use Intakt\Tests\Journeys\Support\RoundStreamCheck;
use Intakt\Tests\Journeys\Support\StreamedPage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\Update;

#[CoversNothing]
#[Group('journeys')]
final class LiveReviewProgressJourneyTest extends JourneyTestCase
{
    // Not a credential, because the seed only checks that a key is present, and no step calls a provider.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';
    private const string ENDED_TEXT = 'Judged.';
    private const string JUDGED_CALL = 'The judge has judged this round. Its call and the findings to fix are in the round below.';
    private const string PROGRESS = '/^\d+ of \d+ reviewers finished\.$/';
    private const string HEADLINE_TARGET = 'target="board-headline"';
    private const string REVIEWING = '/^The Code reviewers are reviewing change \S+ of '.SampleProject::NAME.'\. The Code reviewers are reviewing 1 change\. Nothing needs you\.$/';

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
        $round = $this->step('start a round through the watcher', $this->startTheRound(...));
        $running = $this->step('open the running round', fn (): Crawler => $this->openTheRunningRound($round));
        $updates = $this->step('step the round until the judge has ruled', fn (): array => $this->stepTheRound($round));
        $this->step('check the recorded updates against the running page', fn () => new RoundStreamCheck()->assertFitsThePage($round, $updates, $running));
        $this->step('read the board headline pushed once a reviewer started', fn () => $this->readThePushedHeadline($round));
        $live = $this->step('apply the recorded updates to the running page', fn (): Crawler => new StreamedPage()->after($running, MercureRoundUpdates::topicOf($round), $updates));
        $judged = $this->step('open the judged round', fn (): Crawler => $this->openTheJudgedRound($round));
        $this->step('read the judged content on the running page as a reload shows it', fn () => $this->readTheJudgedContentWithoutAReload($round, $live, $judged));
        $this->step('read the board headline after the judge', $this->readTheBoardHeadline(...));
    }

    private function createTheDatabase(): void
    {
        self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate']));
    }

    private function seedTheSampleProject(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $this->rebuildContainer($this->environment());
        $output = $this->console(['intakt:sample:seed', $this->directory.'/sample', $this->configuration()], $this->environment());

        self::assertStringContainsString('Built the sample repository', $output);
    }

    // The watcher start rebuilds the container after adding the watch section, so the worker that steps the round reads the seeded project too.
    private function startTheRound(): string
    {
        $round = $this->watcherStart($this->environment())->startOnSamplePullRequest($this->directory.'/sample', $this->configuration());

        self::assertNotSame('', $round);

        return $round;
    }

    // Read from the hub, because a reviewer starts and answers within one worker step, so no page load between steps finds it running; its link is the address the step "open the running round" opened.
    private function readThePushedHeadline(string $round): void
    {
        $lines = $this->pushedHeadlines();

        self::assertNotSame([], $lines);
        self::assertMatchesRegularExpression(self::REVIEWING, $lines[0]->text());
        self::assertSame(['/reviews/'.$round], $lines[0]->filter('a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    // Compared with the last pushed line too, because a page that missed no update must end where a new load starts.
    private function readTheBoardHeadline(): void
    {
        $line = $this->visit('/')->filter('#board-headline')->text();

        self::assertSame('Nothing is running. Nothing needs you.', $line);
        self::assertSame($line, array_last($this->pushedHeadlines())?->text());
    }

    /**
     * @return list<Crawler>
     */
    private function pushedHeadlines(): array
    {
        $headlines = array_filter($this->publishedUpdates(), static fn (Update $update): bool => in_array(DashboardTopics::SHELL, $update->getTopics(), true) && str_contains($update->getData(), self::HEADLINE_TARGET));

        return array_values(array_map(static fn (Update $update): Crawler => new Crawler($update->getData())->filter('turbo-stream[target="board-headline"] template'), $headlines));
    }

    private function openTheRunningRound(string $round): Crawler
    {
        // Without a live listener, because the instance has no hub secret to authorize one, so the updates are read from the recording hub.
        $page = $this->visit('/reviews/'.$round);

        self::assertSame('in progress', mb_strtolower($page->filter('.state-running')->text()));
        self::assertMatchesRegularExpression(self::PROGRESS, $page->filter('#round-'.$round.'-state')->text());

        return $page;
    }

    /**
     * @return list<Update>
     */
    private function stepTheRound(string $round): array
    {
        return $this->liveRoundDriver()->stepUntilEnded($round);
    }

    private function openTheJudgedRound(string $round): Crawler
    {
        $page = $this->visit('/reviews/'.$round);

        self::assertStringContainsString(self::ENDED_TEXT, $page->filter('#round-'.$round.'-state')->text());
        self::assertSame('judged', mb_strtolower($page->filter('.state-judged')->text()));

        return $page;
    }

    // Compared with a new load, because a page that missed no update must show the judged round as a reload does.
    private function readTheJudgedContentWithoutAReload(string $round, Crawler $live, Crawler $judged): void
    {
        self::assertSame(self::JUDGED_CALL, $live->filter('#round-'.$round.'-now-judge')->text());
        self::assertSame($judged->filter('#round-'.$round.'-judged')->html(), $live->filter('#round-'.$round.'-judged')->html());
        self::assertSame($judged->filter('#round-'.$round.'-tokens')->html(), $live->filter('#round-'.$round.'-tokens')->html());
        self::assertSame($judged->filter('.page-head p.meta')->html(), $live->filter('.page-head p.meta')->html());
        self::assertSame($judged->filter('#review-summary-cost')->text(), 'Cost: '.$live->filter('#round-'.$round.'-review-cost')->text());
        self::assertStringNotContainsString('Reload', $live->filter('body')->text());
    }

    /**
     * @return array<string,string>
     */
    private function environment(): array
    {
        return ['ANTHROPIC_API_KEY' => self::PLACEHOLDER_KEY, 'INTAKT_CONFIG' => $this->configuration()];
    }

    private function configuration(): string
    {
        return $this->directory.'/intakt.yaml';
    }
}
