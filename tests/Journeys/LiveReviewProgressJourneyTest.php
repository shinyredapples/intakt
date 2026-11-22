<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
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
    private const string ENDED_TEXT = 'Checked.';
    private const string PROGRESS = '/^\d+ of \d+ reviewers finished\.$/';
    private const string NONE_STARTED = '0 of 2 reviewers started.';
    private const string ALL_STARTED = '2 of 2 reviewers started.';
    private const string IDLE = 'Nothing is running.';
    private const string HEADLINE_TARGET = 'target="board-headline"';
    private const string REVIEWING = '/^The Code reviewers are reviewing change \S+ of '.SampleProject::NAME.'\. The Code reviewers are reviewing 1 change\.$/';

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
        $this->step('read the reviewers the updates placed by role and model', fn () => $this->readTheReviewersPlacedLive($round, $updates));
        $this->step('read the board headline pushed once a reviewer started', fn () => $this->readThePushedHeadline($round));
        $judged = $this->step('open the judged round', fn (): Crawler => $this->openTheJudgedRound($round));
        $this->step('read the judged content without a reload', fn () => $this->readTheJudgedContentWithoutAReload($round, new StreamedPage()->after($round, $updates, $running), $judged));
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

    // Read from the hub, because a reviewer starts and answers within one worker step, so no page load between steps finds it running; its link is the address the step "open the running round" opened; past the idle lines, because the progress published at the round's start pushes the headline before any reviewer started.
    private function readThePushedHeadline(string $round): void
    {
        $lines = $this->pushedHeadlines();
        $first = array_find($lines, static fn (Crawler $line): bool => self::IDLE !== $line->text());

        self::assertNotNull($first);
        self::assertMatchesRegularExpression(self::REVIEWING, $first->text());
        self::assertSame(['/reviews/'.$round], $first->filter('a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    // Compared with the last pushed line too, because a page that missed no update must end where a new load starts.
    private function readTheBoardHeadline(): void
    {
        $line = $this->visit('/')->filter('#board-headline')->text();

        self::assertSame(self::IDLE, $line);
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
        $page = $this->bothViews($round);

        self::assertSame('in progress', mb_strtolower($page->filter('.state-running')->text()));
        self::assertMatchesRegularExpression(self::PROGRESS, $page->filter('#round-'.$round.'-state')->text());
        self::assertSame(self::NONE_STARTED, $page->filter('#round-'.$round.'-started')->text());

        return $page;
    }

    // The main view and the Full record as one document, because a listener holds both and each update targets the parts of one of them.
    private function bothViews(string $round): Crawler
    {
        $main = $this->visit('/reviews/'.$round)->filter('body')->html();
        $record = $this->visit('/reviews/'.$round.'/record')->filter('body')->html();

        return new Crawler('<!DOCTYPE html><html><body>'.$main.$record.'</body></html>', 'http://localhost/');
    }

    /**
     * @return list<Update>
     */
    private function stepTheRound(string $round): array
    {
        return $this->liveRoundDriver()->stepUntilEnded($round);
    }

    // Read from the placing streams' markup, because the page opened before any reviewer started, so each reviewer it lists live was placed by them.
    /**
     * @param list<Update> $updates
     */
    private function readTheReviewersPlacedLive(string $round, array $updates): void
    {
        $now = [];
        $table = [];

        foreach ($updates as $update) {
            preg_match_all('#<li class="character-row">(?:<img [^>]+>)+<span><strong>([^<]+)</strong> \((?:<span id="round-'.$round.'-now-reviewer-[^"]+-model"(?: title="[^"]*")?>)?([^<]+?)(?:</span>)?\): <span id="round-'.$round.'-now-reviewer-[^"]+-state">(.+?)</span></li>#s', $update->getData(), $rows, PREG_SET_ORDER);
            $now = [...$now, ...array_map(static fn (array $row): array => [$row[1], $row[2], trim(strip_tags($row[3]))], $rows)];
            preg_match_all('#<template><tr><td>([^<]+)</td>\s*<td(?: id="round-'.$round.'-reviewer-[^"]+-model")?(?: title="[^"]*")?>([^<]+)</td>#', $update->getData(), $rows, PREG_SET_ORDER);
            $table = [...$table, ...array_map(static fn (array $row): array => [$row[1], $row[2]], $rows)];
        }

        self::assertSame(['correctness', 'security'], array_values(array_unique(array_column($now, 0))));
        self::assertNotSame([], $now);
        self::assertSame([], array_diff(array_column($now, 2), ['Approved', 'Changes needed', 'Blocked']));
        self::assertSame(['correctness', 'security'], array_values(array_unique(array_column($table, 0))));
        // Named by a model, because the sample maps a model to every tier, so neither the fallback for an unread start nor the one for an unrecorded model may show.
        self::assertSame([], array_filter([...array_column($now, 1), ...array_column($table, 1)], static fn (string $model): bool => str_contains($model, 'not read') || str_contains($model, 'not recorded')));
        self::assertSame(array_values(array_unique(array_column($now, 1))), array_values(array_unique(array_column($table, 1))));
    }

    private function openTheJudgedRound(string $round): Crawler
    {
        $page = $this->bothViews($round);

        self::assertStringContainsString(self::ENDED_TEXT, $page->filter('#round-'.$round.'-state')->text());
        self::assertSame('checked', mb_strtolower($page->filter('.state-judged')->text()));

        return $page;
    }

    // Compared with a fresh load of the judged round, because a page that stayed open must end showing what a reload would show.
    private function readTheJudgedContentWithoutAReload(string $round, Crawler $live, Crawler $judged): void
    {
        self::assertStringContainsString(self::ENDED_TEXT, $live->filter('#round-'.$round.'-state')->text());
        self::assertSame($judged->filter('#round-'.$round.' .ev-head .chip:not(.replay-mark)')->text(), $live->filter('#review-verdict .verdict-words')->text());
        self::assertSame('0 to fix · 0 urgent', $live->filter('#review-verdict .verdict-count')->text());
        self::assertSame([0, 0], [$live->filter('.state-block')->count(), $judged->filter('.state-block')->count()]);
        self::assertNotSame('', $live->filter('#round-'.$round.'-judged')->text());
        self::assertSame(self::ALL_STARTED, $live->filter('#round-'.$round.'-started')->text());

        foreach (['#review-verdict', '#round-'.$round.'-started', '#round-'.$round.'-verdict', '#round-'.$round.'-judged', '#round-'.$round.'-tokens', '#review-summary-tokens', '#review-summary-cost'] as $part) {
            self::assertSame($judged->filter($part)->text(), $live->filter($part)->text(), $part.' on the page that stayed open differs from a fresh load');
        }

        // Read from the shown text only, because a script's own comments are no text the reader sees.
        $shown = implode(' ', $live->filterXPath('//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::template)]')->each(static fn (Crawler $text): string => $text->text('')));
        self::assertStringNotContainsStringIgnoringCase('reload', $shown);
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
