<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Update;

// Checks what the hub was sent by its structure against the page a listener holds, because a text match passes updates Turbo would drop.
final readonly class RoundStreamCheck
{
    // Matched on these texts because the template of the round_changed stream prints them and the update carries no other flag.
    private const string JUDGED = 'Checked.';
    private const string PROGRESS = '/^(\d+) of (\d+) reviewers finished\.$/';
    // The actions Turbo inserts a stream's template for, because the template of any other action never reaches the page.
    private const array INSERTING = ['append', 'prepend', 'before', 'after', 'replace', 'update'];

    /**
     * @param list<Update> $updates
     */
    public function assertFitsThePage(string $round, array $updates, Crawler $page): void
    {
        $topic = MercureRoundUpdates::topicOf($round);
        $recorded = array_values(array_filter($updates, static fn (Update $update): bool => in_array($topic, $update->getTopics(), true)));
        $ids = $page->filter('[id]')->each(static fn (Crawler $element): string => (string) $element->attr('id'));
        $finished = 0;
        $judgedAt = null;

        foreach ($recorded as $index => $update) {
            $position = $index + 1;
            $this->assertNothingFollowsTheJudgedUpdate($position, $judgedAt);

            foreach ($this->streamsOf($update, $position) as $stream) {
                $this->assertTargetIsOnThePage($stream, $ids, $position);
                $ids = [...$ids, ...$this->idsPlacedBy($stream, $page)];
                $state = $this->stateOf($stream, $round);
                $judgedAt = self::JUDGED === $state ? $position : $judgedAt;
                $finished = $this->progressAfter($finished, $state, $position);
            }
        }

        $this->assertEndedJudged(count($recorded), $judgedAt);
    }

    private function assertNothingFollowsTheJudgedUpdate(int $position, ?int $judgedAt): void
    {
        if (null !== $judgedAt) {
            throw new AssertionFailedError(sprintf('update %d follows update %d, which carries "%s"', $position, $judgedAt, self::JUDGED));
        }
    }

    /**
     * @return list<Crawler>
     */
    private function streamsOf(Update $update, int $position): array
    {
        $document = new Crawler($update->getData());
        $elements = $document->filter('body > *');
        $streams = $document->filter('body > turbo-stream');

        $strayText = $document->filterXPath('//body/text()[normalize-space()]');

        if (0 === count($elements) || count($elements) !== count($streams) || 0 !== count($strayText)) {
            throw new AssertionFailedError(sprintf('update %d is not a <turbo-stream>: %s', $position, $update->getData()));
        }

        $all = $streams->each(static fn (Crawler $stream): Crawler => $stream);

        foreach ($all as $stream) {
            $this->assertActionAndTarget($stream, $position);
        }

        return $all;
    }

    private function assertActionAndTarget(Crawler $stream, int $position): void
    {
        if ('' === (string) $stream->attr('action')) {
            throw new AssertionFailedError(sprintf('update %d has a <turbo-stream> without an action', $position));
        }

        if ('' === (string) $stream->attr('target') && '' === (string) $stream->attr('targets')) {
            throw new AssertionFailedError(sprintf('update %d has a <turbo-stream> without a target or targets', $position));
        }
    }

    /**
     * @param list<string> $ids
     */
    private function assertTargetIsOnThePage(Crawler $stream, array $ids, int $position): void
    {
        $target = (string) $stream->attr('target');

        // Only a single target is looked up, because a targets selector may rightly match nothing, as the one that places a reviewer does once it has its place.
        if ('' !== $target && !in_array($target, $ids, true)) {
            throw new AssertionFailedError(sprintf('update %d targets "%s", which names no element on the page', $position, $target));
        }
    }

    /**
     * @return list<string>
     */
    private function idsPlacedBy(Crawler $stream, Crawler $page): array
    {
        $targets = (string) $stream->attr('targets');

        // Only a targets stream whose selector matches the page adds to it, because Turbo drops one that matches nothing and the reviewer-placing one appends what its later streams target.
        if ('' === $targets || !in_array($stream->attr('action'), self::INSERTING, true) || 0 === count($page->filter($targets))) {
            return [];
        }

        return $this->templateOf($stream)->filter('[id]')->each(static fn (Crawler $element): string => (string) $element->attr('id'));
    }

    private function stateOf(Crawler $stream, string $round): ?string
    {
        if ($stream->attr('target') !== sprintf('round-%s-state', $round)) {
            return null;
        }

        return $this->templateOf($stream)->text('');
    }

    private function templateOf(Crawler $stream): Crawler
    {
        // Read from the template's markup, because a parser may keep a template's content out of the tree, and parsed inside a template, because only there a table row keeps its cells.
        return new Crawler('<template>'.$stream->filter('template')->html('').'</template>')->filter('template');
    }

    private function progressAfter(int $finished, ?string $state, int $position): int
    {
        if (null === $state || self::JUDGED === $state) {
            return $finished;
        }

        if (1 !== preg_match(self::PROGRESS, $state, $progress)) {
            throw new AssertionFailedError(sprintf('update %d shows the round state "%s", which is neither progress nor "%s"', $position, $state, self::JUDGED));
        }

        $now = (int) $progress[1];

        if ($now < $finished) {
            throw new AssertionFailedError(sprintf('update %d shows %d reviewers finished after %d: the progress went backwards', $position, $now, $finished));
        }

        return $now;
    }

    private function assertEndedJudged(int $count, ?int $judgedAt): void
    {
        if (0 === $count) {
            throw new AssertionFailedError('no update was recorded on the round\'s topic');
        }

        if (null === $judgedAt) {
            throw new AssertionFailedError(sprintf('update %d, the last, does not carry "%s"', $count, self::JUDGED));
        }
    }
}
