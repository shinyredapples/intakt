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

#[CoversClass(RoundStreamCheck::class)]
#[UsesClass(MercureRoundUpdates::class)]
final class RoundStreamCheckTest extends TestCase
{
    private const string ROUND = 'round-1';

    // Only the elements the streams look up, because the check reads nothing else of the review page.
    private const string PAGE = <<<'HTML'
        <html><body>
        <p id="round-round-1-state">0 of 2 reviewers finished.</p>
        <div id="round-round-1-live"><section>
        <pre id="reviewer-round-1-correctness-text"></pre>
        <ul id="reviewer-round-1-correctness-activity"></ul>
        </section></div>
        <table><tbody id="round-round-1-reviewers"><tr><td id="round-round-1-reviewer-correctness-state">running</td><td id="round-round-1-reviewer-correctness-stop"></td></tr></tbody></table>
        </body></html>
        HTML;

    public function testARecordingThatFitsThePageAndEndsJudgedPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->check([
            $this->placed('correctness')."\n".$this->update('append', 'reviewer-round-1-correctness-activity', '<li>read_file</li>'),
            $this->progress(1),
            $this->update('replace', 'round-round-1-reviewer-correctness-state', '<td>stopped</td>').$this->update('replace', 'round-round-1-reviewer-correctness-stop', '<td></td>'),
            $this->progress(2),
            $this->judged(),
        ]);
    }

    public function testProgressThatStaysTheSamePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->check([$this->progress(1), $this->progress(1), $this->judged()]);
    }

    public function testAReviewerPlacedAfterThePageLoadedMayBeTargetedOnceItsPlaceIsAppended(): void
    {
        $this->expectNotToPerformAssertions();

        $this->check([
            $this->placed('security').$this->update('append', 'reviewer-round-1-security-text', 'Looking'),
            $this->update('append', 'reviewer-round-1-security-activity', '<li>read_file</li>'),
            $this->judged(),
        ]);
    }

    public function testTheCellsOfATableRowPlacedAfterThePageLoadedMayBeTargeted(): void
    {
        $this->expectNotToPerformAssertions();

        $this->check([
            '<turbo-stream action="append" targets="#round-round-1-reviewers:not(:has(#round-round-1-reviewer-security-state))"><template><tr><td id="round-round-1-reviewer-security-state">verdict</td><td id="round-round-1-reviewer-security-stop"></td></tr></template></turbo-stream>'
            .$this->update('replace', 'round-round-1-reviewer-security-state', '<td id="round-round-1-reviewer-security-state">verdict</td>')
            .$this->update('replace', 'round-round-1-reviewer-security-stop', '<td id="round-round-1-reviewer-security-stop"></td>'),
            $this->judged(),
        ]);
    }

    public function testUpdatesOnAnotherRoundsTopicAreLeftOut(): void
    {
        $this->expectNotToPerformAssertions();

        new RoundStreamCheck()->assertFitsThePage(self::ROUND, [
            $this->onTopic($this->judged()),
            new Update(MercureRoundUpdates::topicOf('round-2'), 'plain text', true),
        ], new Crawler(self::PAGE));
    }

    public function testAPlainTextUpdateFails(): void
    {
        $this->expectFailure('update 2 is not a <turbo-stream>: 1 of 2 reviewers finished.');

        $this->check([$this->progress(1), '1 of 2 reviewers finished.', $this->judged()]);
    }

    public function testAnUpdateThatIsMarkupButNoTurboStreamFails(): void
    {
        $this->expectFailure('update 1 is not a <turbo-stream>');

        $this->check(['<p id="round-round-1-state">Checked.</p>']);
    }

    public function testAnUpdateWithAStreamBesideOtherMarkupFails(): void
    {
        $this->expectFailure('update 1 is not a <turbo-stream>');

        $this->check([$this->judged().'<p>stray</p>']);
    }

    public function testAnUpdateWithAStreamBesidePlainTextFails(): void
    {
        $this->expectFailure('update 1 is not a <turbo-stream>');

        $this->check([$this->judged().'plain text']);
    }

    public function testAStreamWithoutAnActionFails(): void
    {
        $this->expectFailure('update 1 has a <turbo-stream> without an action');

        $this->check(['<turbo-stream target="round-round-1-state"><template><p>Checked.</p></template></turbo-stream>']);
    }

    public function testAStreamWithoutATargetOrTargetsFails(): void
    {
        $this->expectFailure('update 1 has a <turbo-stream> without a target or targets');

        $this->check(['<turbo-stream action="replace"><template><p>Checked.</p></template></turbo-stream>']);
    }

    public function testATargetThatNamesNoElementOnThePageFails(): void
    {
        $this->expectFailure('update 1 targets "reviewer-round-1-security-activity", which names no element on the page');

        $this->check([$this->update('append', 'reviewer-round-1-security-activity', '<li>read_file</li>'), $this->judged()]);
    }

    public function testATargetPlacedByATargetsStreamThatMatchesNothingOnThePageFails(): void
    {
        $this->expectFailure('update 2 targets "reviewer-round-1-security-text", which names no element on the page');

        $this->check([
            str_replace('#round-round-1-live', '#round-round-1-absent', $this->placed('security')),
            $this->update('append', 'reviewer-round-1-security-text', 'Looking'),
            $this->judged(),
        ]);
    }

    public function testATargetPlacedOnlyInTheTemplateOfARemoveStreamFails(): void
    {
        $this->expectFailure('update 2 targets "reviewer-round-1-security-text", which names no element on the page');

        $this->check([
            str_replace('action="append"', 'action="remove"', $this->placed('security')),
            $this->update('append', 'reviewer-round-1-security-text', 'Looking'),
            $this->judged(),
        ]);
    }

    public function testATargetsStreamThatMatchesNothingOnThePageIsNoFailureByItself(): void
    {
        $this->expectNotToPerformAssertions();

        $this->check([$this->placed('correctness'), $this->judged()]);
    }

    public function testProgressThatGoesBackwardsFails(): void
    {
        $this->expectFailure('update 2 shows 0 reviewers finished after 1: the progress went backwards');

        $this->check([$this->progress(1), $this->progress(0), $this->judged()]);
    }

    public function testARoundStateThatIsNeitherProgressNorJudgedFails(): void
    {
        $this->expectFailure('update 1 shows the round state "Almost done.", which is neither progress nor "Checked."');

        $this->check([$this->state('Almost done.')]);
    }

    public function testARecordingWhoseLastUpdateIsNotJudgedFails(): void
    {
        $this->expectFailure('update 2, the last, does not carry "Checked."');

        $this->check([$this->progress(1), $this->progress(2)]);
    }

    public function testAnUpdateAfterTheJudgedOneFails(): void
    {
        $this->expectFailure('update 3 follows update 2, which carries "Checked."');

        $this->check([$this->progress(2), $this->judged(), $this->progress(2)]);
    }

    public function testARecordingWithNothingOnTheRoundsTopicFails(): void
    {
        $this->expectFailure('no update was recorded on the round\'s topic');

        $this->check([]);
    }

    /**
     * @param list<string> $data
     */
    private function check(array $data): void
    {
        new RoundStreamCheck()->assertFitsThePage(self::ROUND, array_map($this->onTopic(...), $data), new Crawler(self::PAGE));
    }

    private function expectFailure(string $message): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($message, '/').'/');
    }

    private function onTopic(string $data): Update
    {
        return new Update(MercureRoundUpdates::topicOf(self::ROUND), $data, true);
    }

    private function progress(int $finished): string
    {
        return $this->state(sprintf('%d of 2 reviewers finished.', $finished));
    }

    private function judged(): string
    {
        return $this->state('Checked.');
    }

    private function state(string $text): string
    {
        return $this->update('replace', 'round-round-1-state', sprintf('<p id="round-round-1-state">%s</p>', $text));
    }

    private function placed(string $reviewer): string
    {
        return sprintf(
            '<turbo-stream action="append" targets="#round-round-1-live:not(:has(#reviewer-round-1-%1$s-text))"><template><section><pre id="reviewer-round-1-%1$s-text"></pre><ul id="reviewer-round-1-%1$s-activity"></ul></section></template></turbo-stream>',
            $reviewer,
        );
    }

    private function update(string $action, string $target, string $content): string
    {
        return sprintf('<turbo-stream action="%s" target="%s"><template>%s</template></turbo-stream>', $action, $target, $content);
    }
}
