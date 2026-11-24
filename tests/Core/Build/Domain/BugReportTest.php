<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\Domain\BugPart;
use Intakt\Core\Build\Domain\BugReport;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ReadableLines;
use Intakt\Core\Review\Contract\Labels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BugReport::class)]
#[CoversClass(ReadableLines::class)]
#[CoversClass(BugPart::class)]
#[CoversClass(TaskKind::class)]
#[CoversClass(BuildQuestion::class)]
#[UsesClass(Labels::class)]
final class BugReportTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function completeBodies(): iterable
    {
        yield 'bold labels' => ["**When:** open the page\n**Today:** it fails\n**Expected:** it loads\n**Must keep working:** the list"];
        yield 'headings' => ["## When\nopen the page\n\n## Today\nit fails\n\n## Expected\nit loads\n\n## Must keep working\nthe list"];
        yield 'bold labels in a list, in any letter case' => ["- **when:** open\n- **TODAY:** fails\n* **Expected:** loads\n- __must keep working__: the list"];
        yield 'bold label with the colon outside' => ["**When**: a\n**Today**: b\n**Expected**: c\n**Must keep working**: d"];
        yield 'headings with a colon and closing hashes' => ["### When: ###\na\n## today\nb\n# Expected:\nc\n## Must keep working ##\nd"];
        yield 'bold labels in a numbered list' => ["1. **When:** open\n2. **Today:** fails\n3) **Expected:** loads\n4. **Must keep working:** the list"];
        yield 'underlined headings' => ["When\n====\nopen\n\nToday\n-----\nfails\n\nExpected\n---\nloads\n\nMust keep working\n===\nthe list"];
        yield 'headings in list items' => ["- ## When\n  open\n- ## Today\n  fails\n- ## Expected\n  loads\n- ## Must keep working\n  the list"];
        yield 'an inline code span of three backticks' => ["**When:** open\n```php echo 1; ```\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list"];
    }

    public function testANumberedBoldLabelEndsAPartSoAnEmptyPartBeforeItIsMissing(): void
    {
        $report = BugReport::fromBody("1. **When:** open\n2. **Today:** fails\n3. **Expected:** loads\n4. **Must keep working:**\n5. **Acceptance:** a test");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAnUnderlinedHeadingEndsAPartSoAnEmptyPartBeforeItIsMissing(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\n\nAcceptance\n----------\na test");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testADashLineAfterABlankLineIsARuleNotAHeading(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list\n\n---\nthanks");

        self::assertSame([], $report->missingParts());
    }

    public function testAFenceOpenerInsideAnHtmlCommentHidesNothingAfterTheComment(): void
    {
        $report = BugReport::fromBody("<!-- template:\n```\n-->\n**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testACommentOpenerInsideAFenceHidesNothingAfterTheFence(): void
    {
        $report = BugReport::fromBody("**When:** open\n```html\n<!--\n```\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testAFenceIndentedInAListItemDoesNotFillAPart(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n- an example:\n\n    ```\n    **Must keep working:** the list\n    ```");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAFenceInAListItemEndsWithTheItemSoThePartsAfterTheListCount(): void
    {
        $report = BugReport::fromBody("- Example:\n\n  ```text\n  sample\n\n**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testBackticksInIndentedCodeOpenNoFenceSoThePartsAfterThemCount(): void
    {
        $report = BugReport::fromBody("    ```\n\n**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testAFenceIndentedThreeColumnsStillHidesTheLinesUpToItsEnd(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n   ```\n**Must keep working:** the list\n   ```");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAHeadingInAListItemEndsAPartSoAnEmptyPartBeforeItIsMissing(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\n- ## Acceptance\n\n  A test passes");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deepFenceClosers(): iterable
    {
        yield 'four spaces' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n```\nexample\n    ```\n**Must keep working:** the list"];
        yield 'one tab' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n```\nexample\n\t```\n**Must keep working:** the list"];
        yield 'four columns past a list item' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n- an example:\n\n  ```\n  example\n      ```\n  **Must keep working:** the list"];
    }

    #[DataProvider('deepFenceClosers')]
    public function testAFenceCloserFourColumnsIntoItsContainerIsContentSoTheFenceStaysOpen(string $body): void
    {
        self::assertSame([BugPart::MustKeepWorking], BugReport::fromBody($body)->missingParts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shallowFenceClosers(): iterable
    {
        foreach ([0, 1, 2, 3] as $columns) {
            $indent = str_repeat(' ', $columns);

            yield $columns.' columns outside a list' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n```\nexample\n{$indent}```\n**Must keep working:** the list"];
            yield $columns.' columns past a list item' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n- an example:\n\n  ```\n  example\n  {$indent}```\n  **Must keep working:** the list"];
        }
    }

    #[DataProvider('shallowFenceClosers')]
    public function testAFenceCloserAtMostThreeColumnsIntoItsContainerClosesTheFence(string $body): void
    {
        self::assertSame([], BugReport::fromBody($body)->missingParts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deepCommentClosers(): iterable
    {
        yield 'an external block' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n<!-- external -->\n**Must keep working:** a quote\n        <!-- /external -->\n**Must keep working:** the list"];
        yield 'an HTML comment' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n<!--\n**Must keep working:** a template\n        -->\n**Must keep working:** the list"];
    }

    #[DataProvider('deepCommentClosers')]
    public function testAnExternalBlockOrACommentClosesAtAnyIndentation(string $body): void
    {
        self::assertSame([], BugReport::fromBody($body)->missingParts());
    }

    public function testACommentOpenerInsideInlineCodeOpensNoComment(): void
    {
        $report = BugReport::fromBody("**When:** enter `<!--`\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testACommentAfterInlineCodeIsStillRemoved(): void
    {
        $report = BugReport::fromBody("**When:** enter `x`\n**Today:** fails\n**Expected:** loads\n**Must keep working:** <!-- the list -->");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAnUnderlinedHeadingInAListItemIsNotReadAsAPart(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n- Must keep working\n  =================\n  the list");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAnIndentedCodeBlockIsReadLikeOtherText(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n\n    **Must keep working:** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testALongParagraphIsReadWithoutRescanningItForEveryLine(): void
    {
        $body = "**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\n".str_repeat("x\n", 16000);
        $start = hrtime(true);

        BugReport::fromBody($body);

        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
    }

    public function testAnEmptyLastPartFollowedByAnotherBoldLabelIsMissing(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\n**Acceptance:**\n- a test");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAPartHoldingOnlyAnHtmlCommentIsMissing(): void
    {
        $report = BugReport::fromBody("## When\nopen\n## Today\n<!-- describe what happens\nnow -->\n## Expected\nloads\n## Must keep working\nthe list");

        self::assertSame([BugPart::Today], $report->missingParts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotedParts(): iterable
    {
        yield 'a backtick fence' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n```\n**Must keep working:** the list\n```"];
        yield 'a tilde fence with an info string' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n~~~~markdown\n## Must keep working\nthe list\n~~~~"];
        yield 'an external block' => ["**When:** open\n**Today:** fails\n**Expected:** loads\n<!-- external -->\n**Must keep working:** the list\n<!-- /external -->"];
    }

    #[DataProvider('quotedParts')]
    public function testAPartWrittenInsideAFenceOrAnExternalBlockDoesNotCount(string $body): void
    {
        self::assertSame([BugPart::MustKeepWorking], BugReport::fromBody($body)->missingParts());
    }

    public function testAFenceInsideAPartNeitherFillsNorEndsIt(): void
    {
        $report = BugReport::fromBody("**When:**\n```\n## Today\nfails\n```\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([BugPart::When], $report->missingParts());
    }

    public function testAnExternalBlockInsideAPartDoesNotEndItSoTheTextAfterItCounts(): void
    {
        $report = BugReport::fromBody("**When:**\n<!-- external -->\n## Note\n<!-- /external -->\nopen the page\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testAMustKeepWorkingHeadingWithClosingHashesAndNothingUnderItIsMissing(): void
    {
        $report = BugReport::fromBody("## When\nopen\n## Today\nfails\n## Expected\nloads\n## Must keep working ##\n");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAHeadingThatOnlyStartsWithAPartsNameIsNoPart(): void
    {
        $report = BugReport::fromBody("## When I open the page\nit fails\n## Today\nfails\n## Expected\nloads\n## Must keep working assumptions\nthe list");

        self::assertSame([BugPart::When, BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testABoldLabelFollowedByTextOnTheSameLineFillsThePart(): void
    {
        $report = BugReport::fromBody("**When** I open it\n**Today** it fails\n**Expected** it loads\n**Must keep working** the list");

        self::assertSame([], $report->missingParts());
    }

    public function testAPlainWordWithAColonInsideAPartDoesNotEndIt(): void
    {
        $report = BugReport::fromBody("**When:**\nSteps:\n1. open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\nToday: the list");

        self::assertSame([], $report->missingParts());
    }

    public function testAPlainLabelWithAColonStartsNoPart(): void
    {
        $report = BugReport::fromBody("When: open\n**Today:** fails\n**Expected:** loads\n**Must keep working:** the list");

        self::assertSame([BugPart::When], $report->missingParts());
    }

    public function testABoldTextThatIsNoPartsNameEndsThePart(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\n- **Note** the list stays");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    #[DataProvider('completeBodies')]
    public function testABugBodyWithWhenTodayExpectedAndMustKeepWorkingHasNoMissingPart(string $body): void
    {
        self::assertSame([], BugReport::fromBody($body)->missingParts());
    }

    public function testABugBodyWithoutMustKeepWorkingNamesThatPartAsMissing(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testMissingPartsComeInTheOrderTheTaskStatesThem(): void
    {
        self::assertSame([BugPart::When, BugPart::Expected], BugReport::fromBody("**Today:** fails\n**Must keep working:** the list")->missingParts());
    }

    public function testAPartWithAHeadingButNoTextCountsAsMissing(): void
    {
        $report = BugReport::fromBody("## When\nopen\n## Today\n\n## Expected\nloads\n## Must keep working\n   \n");

        self::assertSame([BugPart::Today, BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testAnotherHeadingEndsAPartSoItsTextDoesNotCountForIt(): void
    {
        $report = BugReport::fromBody("**When:** open\n**Today:** fails\n**Expected:** loads\n**Must keep working:**\n## Acceptance\n- a test");

        self::assertSame([BugPart::MustKeepWorking], $report->missingParts());
    }

    public function testASentenceThatOnlyStartsWithTheWordWhenIsNoPart(): void
    {
        self::assertSame(BugPart::cases(), BugReport::fromBody("When I open the page it fails.\nToday it is slow.\n## Whenever\ntext")->missingParts());
    }

    public function testAnEmptyBodyMissesEveryPart(): void
    {
        self::assertSame(BugPart::cases(), BugReport::fromBody('')->missingParts());
    }

    public function testOnlyTheBugLabelMakesATaskABug(): void
    {
        self::assertSame([TaskKind::Bug, TaskKind::Task, TaskKind::Task], [
            TaskKind::fromLabels(new Labels('intakt', 'bug')),
            TaskKind::fromLabels(new Labels('intakt', 'bugfix')),
            TaskKind::fromLabels(new Labels()),
        ]);
    }

    public function testTheQuestionForMissingPartsNamesThemAndRecommendsAddingThem(): void
    {
        $question = BuildQuestion::forMissingBugParts([BugPart::Today, BugPart::MustKeepWorking]);

        self::assertStringContainsString('missing: Today, Must keep working.', $question->question);
        self::assertSame(['Add the missing parts to the task', 'Remove the bug label'], $question->choices);
        self::assertSame('Add the missing parts to the task', $question->recommendation);
    }
}
