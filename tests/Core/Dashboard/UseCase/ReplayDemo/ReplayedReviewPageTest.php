<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use DOMNode;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\ReplayedJudgedState;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\JudgedRoundEnds;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Tests\Core\Dashboard\Infrastructure\PublishedLiveEvents;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardPageTestCase;
use Intakt\Tests\Core\Dashboard\UseCase\DownloadedScriptStandIns;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Twig\Environment;

// The keyless demo is what a juror without a key plays, so the review page open while the shipped recording plays must end in the main view a reload shows (#3470).
#[CoversClass(ReplaysRecording::class)]
#[CoversClass(ReplayedJudgedState::class)]
#[CoversClass(JudgedRoundEnds::class)]
#[UsesClass(MercureRoundUpdates::class)]
#[UsesClass(RecordingFormat::class)]
#[UsesClass(Recording::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LiveEvent::class)]
final class ReplayedReviewPageTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string SHIPPED = '/fixtures/demo/sample-change-review.json';

    private PublishedLiveEvents $updates;

    public function testThePageOpenWhileTheShippedRecordingPlaysGetsTheOwlsCardWhenTheReplayReachesTheJudge(): void
    {
        [$round, $page] = $this->aPageOpenedOnceTheReplayStarted();

        $updated = $this->streamedTo($page, $round);

        self::assertSame($round, $updated->filter('section#review-verdict')->attr('data-round'));
        self::assertSame(['Changes needed', '5 to fix'], [$updated->filter('#review-verdict .verdict-words')->text(), $updated->filter('#review-verdict .verdict-count')->text()]);
    }

    public function testThePageOpenWhileTheShippedRecordingPlaysGetsOneToFixCardPerFindingToFix(): void
    {
        [$round, $page] = $this->aPageOpenedOnceTheReplayStarted();

        $updated = $this->streamedTo($page, $round);

        self::assertSame(['Urgent', 'Urgent', 'Should fix', 'Should fix', 'Nice to have'], $updated->filter('#review-to-fix .fix-card .urgency')->each(static fn (Crawler $urgency): string => $urgency->text()));
    }

    public function testThePageOpenWhileTheShippedRecordingPlaysNoLongerShowsTheNowBoxAfterTheVerdict(): void
    {
        [$round, $page] = $this->aPageOpenedOnceTheReplayStarted();

        $updated = $this->streamedTo($page, $round);

        self::assertCount(0, $updated->filter('#review-state-block'));
        self::assertCount(0, $updated->filter('#round-'.$round.'-now-judge'));
    }

    public function testTheJudgedStateComesAfterEveryRecordedUpdateOfTheRound(): void
    {
        [$round] = $this->aPageOpenedOnceTheReplayStarted();

        $topic = MercureRoundUpdates::topicOf($round);
        $ofRound = array_values(array_filter($this->updates->events, static fn (LiveEvent $event): bool => $topic === $event->topic));

        $last = array_last($ofRound);
        self::assertInstanceOf(LiveEvent::class, $last);
        self::assertStringContainsString('target="review-verdict"', $last->turboStream);
        self::assertCount(1, array_filter($ofRound, static fn (LiveEvent $event): bool => str_contains($event->turboStream, 'target="review-verdict"')));
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->flagAsReplay(CreateDemoProjectCommand::NAME);
        $this->givenTheDownloadedScriptsExist();
        $this->updates = new PublishedLiveEvents();
        $this->browser->disableReboot();
        // No stored summary, as for a replayed round, so the round's own streams are the only updates.
        self::getContainer()->set(SessionSummaries::class, self::createStub(SessionSummaries::class));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeTheStandIns();
        parent::tearDown();
    }

    /**
     * @return array{string, Crawler}
     */
    private function aPageOpenedOnceTheReplayStarted(): array
    {
        $shipped = $this->shipped();
        $first = new Recording($shipped->reviewedAt, $shipped->intaktVersion, $shipped->models, $shipped->project, $shipped->change, [$shipped->events[0]], []);
        $rest = new Recording($shipped->reviewedAt, $shipped->intaktVersion, $shipped->models, $shipped->project, $shipped->change, array_slice($shipped->events, 1), $shipped->updates);
        $start = json_decode($shipped->events[0]->payload, true);
        self::assertIsArray($start);
        $round = $start['round'] ?? null;
        self::assertIsString($round);

        $this->replays()->replay($first, 1000.0);
        $page = $this->bothViews('/reviews/'.$round);
        $this->replays()->replay($rest, 1000.0);

        return [$round, $page];
    }

    private function shipped(): Recording
    {
        $text = file_get_contents(dirname(__DIR__, 5).self::SHIPPED);
        self::assertIsString($text);

        return new RecordingFormat(StoredSerializerFactory::create())->read($text);
    }

    // At a pace no clock waits for, because the mock clock's sleep only moves its moment; the judged state goes out through the shipped renderer and templates.
    private function replays(): ReplaysRecording
    {
        $container = self::getContainer();
        $twig = $container->get(Environment::class);
        $summaries = $container->get(SessionSummaries::class);
        $pages = $container->get(LedgerReviewPages::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(SessionSummaries::class, $summaries);
        self::assertInstanceOf(LedgerReviewPages::class, $pages);
        $rounds = new MercureRoundUpdates($this->updates, $twig, $summaries, $pages, new LockFactory(new InMemoryStore()), new NullLogger());
        $marks = new ReplayMarks(new MarkAnsweringQueries(ReplayMark::Replay));

        return new ReplaysRecording($this->ledger, $this->updates, new MockClock('2026-11-22T09:00:00+00:00'), new LockFactory(new InMemoryStore()), $marks, TourCaptionsOn::captions($this->updates, TourCaptionsOn::tour($this->ledger, $marks, '')), new ReplayedJudgedState($pages, $rounds));
    }

    // The main view and the Full record as one document, because a listener holds both and each stream targets the parts of one of them.
    private function bothViews(string $review): Crawler
    {
        $main = $this->browser->request('GET', $review)->filter('body')->html();
        $record = $this->browser->request('GET', $review.'/record')->filter('body')->html();

        return new Crawler('<!DOCTYPE html><html><body>'.$main.$record.'</body></html>', 'http://localhost/');
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests; like Turbo, a stream whose target is absent changes nothing.
    private function streamedTo(Crawler $page, string $round): Crawler
    {
        $topic = MercureRoundUpdates::topicOf($round);

        foreach ($this->updates->events as $event) {
            if ($topic !== $event->topic) {
                continue;
            }

            foreach (new Crawler($event->turboStream)->filter('turbo-stream')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
                $selector = $stream->attr('targets') ?? '#'.$stream->attr('target');
                foreach ($page->filter($selector)->each(static fn (Crawler $target): Crawler => $target) as $target) {
                    $this->applied($stream, $target);
                }
            }
        }

        return $page;
    }

    private function applied(Crawler $stream, Crawler $target): void
    {
        $node = $target->getNode(0);
        self::assertNotNull($node);
        $document = $node->ownerDocument;
        self::assertNotNull($document);

        if ('remove' === $stream->attr('action')) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $wrapper = new Crawler('<template>'.$stream->filter('template')->html().'</template>')->filter('template')->getNode(0);
        self::assertNotNull($wrapper);

        if ('replace' === $stream->attr('action')) {
            foreach (iterator_to_array($wrapper->childNodes) as $child) {
                $node->parentNode?->insertBefore($document->importNode($child, true), $node);
            }
            $node->parentNode?->removeChild($node);

            return;
        }

        if ('update' === $stream->attr('action')) {
            while ($node->firstChild instanceof DOMNode) {
                $node->removeChild($node->firstChild);
            }
        }

        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $node->appendChild($document->importNode($child, true));
        }
    }
}
