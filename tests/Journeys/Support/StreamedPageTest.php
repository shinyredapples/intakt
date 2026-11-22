<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Update;

#[CoversClass(StreamedPage::class)]
#[UsesClass(MercureRoundUpdates::class)]
final class StreamedPageTest extends TestCase
{
    private const string ROUND = 'round-1';

    private const string PAGE = <<<'HTML'
        <html><body>
        <p id="round-round-1-state">0 of 2 reviewers finished.</p>
        <p id="round-round-1-now-judge">The judge has not judged this round yet.</p>
        <ul id="round-round-1-activity"><li>read_file</li></ul>
        <table><tbody id="round-round-1-reviewers"><tr><td id="round-round-1-reviewer-correctness-state">running</td></tr></tbody></table>
        </body></html>
        HTML;

    public function testAReplaceStreamSwapsTheElementWithItsTargetIdForTheTemplatesContent(): void
    {
        $page = $this->streamed($this->stream('replace', 'target="round-round-1-now-judge"', '<p id="round-round-1-now-judge">Judged below.</p>'));

        self::assertSame('<p id="round-round-1-now-judge">Judged below.</p>', $page->filter('#round-round-1-now-judge')->outerHtml());
    }

    public function testAnUpdateStreamReplacesOnlyTheTargetsContent(): void
    {
        $page = $this->streamed($this->stream('update', 'target="round-round-1-state"', 'Judged.'));

        self::assertSame('<p id="round-round-1-state">Judged.</p>', $page->filter('#round-round-1-state')->outerHtml());
    }

    public function testAnAppendStreamAddsTheTemplatesContentAfterTheTargetsChildren(): void
    {
        $page = $this->streamed($this->stream('append', 'target="round-round-1-activity"', '<li>write_file</li>'));

        self::assertSame(['read_file', 'write_file'], $page->filter('#round-round-1-activity li')->each(static fn (Crawler $item): string => $item->text()));
    }

    public function testATargetsStreamAppliesToEveryElementItsSelectorMatchesKeepingTableRows(): void
    {
        $page = $this->streamed($this->stream('append', 'targets="table > #round-round-1-reviewers:not(:has(#round-round-1-reviewer-security-state))"', '<tr><td id="round-round-1-reviewer-security-state">running</td></tr>'));

        self::assertSame(['running', 'running'], $page->filter('#round-round-1-reviewers td')->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testATargetsStreamWhoseSelectorMatchesNothingChangesNothing(): void
    {
        $page = $this->streamed($this->stream('append', 'targets="#round-round-1-activity:not(:has(li))"', '<li>write_file</li>'));

        self::assertSame(['read_file'], $page->filter('#round-round-1-activity li')->each(static fn (Crawler $item): string => $item->text()));
    }

    public function testARemoveStreamTakesTheTargetOffThePage(): void
    {
        $page = $this->streamed($this->stream('remove', 'target="round-round-1-activity"', ''));

        self::assertCount(0, $page->filter('#round-round-1-activity'));
    }

    public function testARemoveWhoseTargetAnEarlierUpdateRemovedChangesNothing(): void
    {
        $remove = $this->stream('remove', 'target="round-round-1-activity"', '');

        $page = new StreamedPage()->after(self::ROUND, [$this->update($remove), $this->update($remove)], $this->page());

        self::assertCount(1, $page->filter('#round-round-1-state'));
        self::assertCount(0, $page->filter('#round-round-1-activity'));
    }

    public function testStreamsOfAnUpdateApplyInOrderSoALaterOneSeesWhatAnEarlierOnePlaced(): void
    {
        $page = $this->streamed(
            $this->stream('replace', 'target="round-round-1-activity"', '<ol id="round-round-1-calls"></ol>')
            .$this->stream('append', 'target="round-round-1-calls"', '<li>grep</li>'),
        );

        self::assertSame('grep', $page->filter('#round-round-1-calls li')->text());
    }

    public function testAnUpdateOnAnotherTopicLeavesThePageAsItWas(): void
    {
        $page = new StreamedPage()->after(self::ROUND, [new Update('review/rounds/round-2', $this->stream('remove', 'target="round-round-1-activity"', ''))], $this->page());

        self::assertCount(1, $page->filter('#round-round-1-activity'));
    }

    public function testThePageTheListenerOpenedIsLeftAsItWas(): void
    {
        $opened = $this->page();

        new StreamedPage()->after(self::ROUND, [$this->update($this->stream('remove', 'target="round-round-1-activity"', ''))], $opened);

        self::assertCount(1, $opened->filter('#round-round-1-activity'));
    }

    public function testAStreamWhoseTargetIsNotOnThePageFailsWithTheTargetNamed(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/^'.preg_quote('update 1 targets "round-round-1-verdict", which names no element on the page', '/').'$/');

        $this->streamed($this->stream('replace', 'target="round-round-1-verdict"', '<div id="round-round-1-verdict"></div>'));
    }

    public function testAStreamWithAnActionThePageDoesNotApplyFailsWithTheActionNamed(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/^'.preg_quote('update 1 has the action "refresh", which the streamed page does not apply', '/').'$/');

        $this->streamed($this->stream('refresh', 'target="round-round-1-state"', ''));
    }

    private function update(string $data): Update
    {
        return new Update(MercureRoundUpdates::topicOf(self::ROUND), $data);
    }

    private function stream(string $action, string $target, string $template): string
    {
        return sprintf('<turbo-stream action="%s" %s><template>%s</template></turbo-stream>', $action, $target, $template);
    }

    private function streamed(string $data): Crawler
    {
        return new StreamedPage()->after(self::ROUND, [$this->update($data)], $this->page());
    }

    private function page(): Crawler
    {
        return new Crawler(self::PAGE);
    }
}
