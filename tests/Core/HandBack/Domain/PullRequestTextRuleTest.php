<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\EvidenceItems;
use Intakt\Core\HandBack\Domain\FencedBlock;
use Intakt\Core\HandBack\Domain\HiddenBlockOpeners;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PullRequestTextRule::class)]
#[CoversClass(PullRequestTextOutcome::class)]
#[CoversClass(PullRequestTextVerdict::class)]
#[UsesClass(EvidenceItems::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
#[UsesClass(CodeSpans::class)]
#[UsesClass(FencedBlock::class)]
#[UsesClass(HiddenBlockOpeners::class)]
#[UsesClass(OpenListItems::class)]
#[UsesClass(ParagraphEnd::class)]
final class PullRequestTextRuleTest extends TestCase
{
    private const string TASK = "Build it.\n\nEvidence:\n- E1. A red check.\n- E2. A green check.\n- E3. The docs.\n";

    public function testABodyThatReferencesEveryEvidenceItemPasses(): void
    {
        $outcome = PullRequestTextRule::judge(self::TASK, "## Evidence\n- E1: red\n- E2: green\n- E3: docs\n");

        self::assertTrue($outcome->passed());
        self::assertSame([PullRequestTextVerdict::Passed, 3, []], [$outcome->verdict, $outcome->evidenceItems, $outcome->missingItems]);
    }

    public function testABodyMissingAnEvidenceItemIsRefusedNamingIt(): void
    {
        $outcome = PullRequestTextRule::judge(self::TASK, "- E1: red\n- E3: docs\n");

        self::assertFalse($outcome->passed());
        self::assertSame([PullRequestTextVerdict::MissingEvidenceReference, [2]], [$outcome->verdict, $outcome->missingItems]);
    }

    public function testAReferenceToE10IsNoReferenceToE1(): void
    {
        $outcome = PullRequestTextRule::judge("Evidence:\n- E1. one\n", 'See E10 and XE1 and E1a.');

        self::assertSame([1], $outcome->missingItems);
    }

    public function testAReferenceFollowedByPunctuationCounts(): void
    {
        self::assertTrue(PullRequestTextRule::judge("Evidence:\n- E1. one\n- E2. two\n", '(E1), E2: done')->passed());
    }

    public function testAReferenceInLowerCaseDoesNotCount(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("Evidence:\n- E1. one\n", 'e1: done')->missingItems);
    }

    public function testAReferenceOnlyInAnHtmlCommentDoesNotCount(): void
    {
        $outcome = PullRequestTextRule::judge("## Evidence\n- E1: Show the command output\n", "## Evidence\nNo output provided.\n<!-- E1: placeholder -->");

        self::assertSame([PullRequestTextVerdict::MissingEvidenceReference, [1]], [$outcome->verdict, $outcome->missingItems]);
    }

    public function testAReferenceInAnUnclosedHtmlCommentDoesNotCount(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("Evidence:\n- E1. one\n", "Done.\n<!--\nE1: later")->missingItems);
    }

    public function testAReferenceAfterAClosedHtmlCommentCounts(): void
    {
        self::assertTrue(PullRequestTextRule::judge("Evidence:\n- E1. one\n- E2. two\n", "<!-- note --> E1: red\n<!--\nhidden\n--> E2: green")->passed());
    }

    public function testACommentMarkerInsideACodeFenceDoesNotHideTheReference(): void
    {
        self::assertTrue(PullRequestTextRule::judge("Evidence:\n- E1. one\n", "```\n<!-- not a comment here\nE1: shown\n```\n")->passed());
    }

    public function testTheProbedEvidenceSectionShapesAskForTheirReference(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\nShow these results:\n\n- E1. The command output\n", 'Completed.')->missingItems);
        self::assertSame([1], PullRequestTextRule::judge("Evidence\n--------\n\n- E1. The command output\n", 'Completed.')->missingItems);
        self::assertSame([2], PullRequestTextRule::judge("## Evidence\n  - E1. A red result\n  - E2. A green result\n", 'E1: red')->missingItems);
        self::assertSame([1], PullRequestTextRule::judge("~~~markdown\n```\n~~~\n\n## Evidence\n- E1. Red and green results\n", 'Completed.')->missingItems);
    }

    public function testABodyMissingTheItemAfterAHeadingNestedInAnItemIsRefused(): void
    {
        $outcome = PullRequestTextRule::judge("## Evidence\n- E1. first\n\n  ### Details\n\n  More text.\n\n- E2. second", 'E1: done');

        self::assertSame([PullRequestTextVerdict::MissingEvidenceReference, [2]], [$outcome->verdict, $outcome->missingItems]);
    }

    public function testABodyMissingAnItemWithATabAfterItsMarkerIsRefused(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n-\tE1. output", 'Done')->missingItems);
    }

    public function testABodyMissingTheItemAfterAnUnclosedFenceInAnItemIsRefused(): void
    {
        self::assertSame([2], PullRequestTextRule::judge("## Evidence\n- E1. output\n\n  ```\n  example\n- E2. second result", 'E1: done')->missingItems);
    }

    public function testAReferenceInsideCommentMarkersInACodeSpanCounts(): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- E1. output", 'Output: `<!-- E1 -->`')->missingItems);
    }

    public function testATaskWithoutEvidenceAsksForNoReference(): void
    {
        self::assertTrue(PullRequestTextRule::judge('Fix the typo.', 'Fixed.')->passed());
    }

    public function testABodyExactlyAtTheCapPasses(): void
    {
        $outcome = PullRequestTextRule::judge('Fix it.', str_repeat('a', PullRequestTextRule::MAX_BODY_CHARACTERS));

        self::assertTrue($outcome->passed());
        self::assertSame(PullRequestTextRule::MAX_BODY_CHARACTERS, $outcome->bodyCharacters);
    }

    public function testABodyOneCharacterOverTheCapIsRefused(): void
    {
        $outcome = PullRequestTextRule::judge('Fix it.', str_repeat('a', PullRequestTextRule::MAX_BODY_CHARACTERS + 1));

        self::assertSame([PullRequestTextVerdict::BodyOverCap, PullRequestTextRule::MAX_BODY_CHARACTERS + 1], [$outcome->verdict, $outcome->bodyCharacters]);
    }

    public function testTheCapCountsCharactersNotBytes(): void
    {
        self::assertTrue(PullRequestTextRule::judge('Fix it.', str_repeat('ä', PullRequestTextRule::MAX_BODY_CHARACTERS))->passed());
    }

    public function testABodyOverTheCapIsRefusedBeforeItsReferencesAreRead(): void
    {
        $outcome = PullRequestTextRule::judge(self::TASK, 'E1 E2 '.str_repeat('a', PullRequestTextRule::MAX_BODY_CHARACTERS));

        self::assertSame([PullRequestTextVerdict::BodyOverCap, []], [$outcome->verdict, $outcome->missingItems]);
        self::assertGreaterThan(PullRequestTextRule::MAX_BODY_CHARACTERS, $outcome->bodyCharacters);
    }

    public function testABodyAtTheCapThatIsOneCodeSpanIsJudged(): void
    {
        $body = '`E1 '.str_repeat('x', PullRequestTextRule::MAX_BODY_CHARACTERS - 5).'`';
        $outcome = PullRequestTextRule::judge("## Evidence\n- E1. output", $body);

        self::assertSame([PullRequestTextVerdict::Passed, PullRequestTextRule::MAX_BODY_CHARACTERS], [$outcome->verdict, $outcome->bodyCharacters]);
    }

    #[TestWith(['`'])]
    #[TestWith(['~'])]
    public function testATaskWithAFenceRunLongerThanTheLargestRegexQuantifierIsJudged(string $character): void
    {
        $run = str_repeat($character, 65_536);
        $task = "## Evidence\n- E1. output\n\n".$run."\n- hidden\n".$run."\n\n- E2. shown";
        $outcome = PullRequestTextRule::judge($task, 'E1');

        self::assertLessThan(256 * 1024, strlen($task));
        self::assertSame([2, [2]], [$outcome->evidenceItems, $outcome->missingItems]);
    }

    #[TestWith(['`'])]
    #[TestWith(['~'])]
    public function testAnUnclosedFenceRunLongerThanTheLargestRegexQuantifierHidesTheRestOfTheTask(string $character): void
    {
        $task = "## Evidence\n- E1. output\n\n".str_repeat($character, 65_536)."\n\n- E2. hidden";

        self::assertSame(PullRequestTextVerdict::Passed, PullRequestTextRule::judge($task, 'E1')->verdict);
    }

    public function testABodyWithAFenceRunAsLongAsTheCapAllowsIsJudged(): void
    {
        $run = str_repeat('~', (PullRequestTextRule::MAX_BODY_CHARACTERS - 20) / 2);
        $body = $run."\nE1\n".$run."\n<!-- E2 -->";

        self::assertSame([2], PullRequestTextRule::judge("## Evidence\n- E1. a\n- E2. b", $body)->missingItems);
    }

    public function testATaskOfManyUnpairedBackticksIsRead(): void
    {
        $task = "## Evidence\n- E1. ".str_repeat('\`` ', 60_000)."\n- E2. b";

        self::assertSame([2], PullRequestTextRule::judge($task, 'E1')->missingItems);
    }

    #[TestWith(["## Heading `\n<!-- E1 -->\n`"], 'an ATX heading')]
    #[TestWith(["Heading `\n=======\n<!-- E1 -->\n`"], 'a setext heading')]
    #[TestWith(["Intro `\n- ~~~\n  content\n<!-- E1 -->\n`"], 'a fence opened after a list marker')]
    #[TestWith(["Intro `\n- ~~~\n  content\n  ~~~\n<!-- E1 -->\n`"], 'a closed fence in a list item')]
    #[TestWith(["Intro `\n```not-a-fence`\n<!-- E1 -->"], 'a line of inline code that looks like a fence')]
    public function testAReferenceInACommentBehindABacktickThatCannotCrossABlockIsMissing(string $body): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", $body)->missingItems);
    }

    public function testACommentAfterAFenceClosedAtTheContentColumnOfAnOrderedItemIsHidden(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", "10. ```\n    Example\n    ```\n    <!-- E1 -->")->missingItems);
    }

    public function testACommentLineAfterAStrayBacktickIsHidden(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", "Intro `\n<!-- E1 -->\n`")->missingItems);
    }

    #[TestWith(['```'], 'backticks')]
    #[TestWith(['~~~'], 'tildes')]
    public function testAReferenceInAFenceAtTheContentColumnOfATwoDigitItemCounts(string $fence): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- E1. output", "10. Example\n\n    {$fence}\n    <!-- E1 -->\n    {$fence}")->missingItems);
    }

    #[TestWith(['```'], 'backticks')]
    #[TestWith(['~~~'], 'tildes')]
    public function testAFenceIndentedFourColumnsPastItsItemsContentOpensNoFence(string $fence): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", "- Example\n\n      {$fence}\n  <!-- E1 -->")->missingItems);
    }

    #[TestWith(['```'], 'backticks')]
    #[TestWith(['~~~'], 'tildes')]
    public function testAFenceIndentedFourSpacesOutsideAnyItemOpensNoFence(string $fence): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", "Intro\n\n    {$fence}\n\n<!-- E1 -->")->missingItems);
    }

    public function testAReferenceInAFenceOpenedAfterATabbedMarkerCounts(): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- E1. output", "-\t```\n\t<!-- E1 -->\n\n\t```")->missingItems);
    }

    public function testACommentAfterAFenceOpenedAfterATabbedMarkerIsHidden(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", "-\t~~~\n\tx\n\t~~~\n\t<!-- E1 -->")->missingItems);
    }

    public function testABodyOfFortyThousandCharactersWithACodeSpanIsOverTheCap(): void
    {
        $body = '`'.str_repeat('x', 40_000 - 5).'` E1';

        self::assertSame(PullRequestTextVerdict::BodyOverCap, PullRequestTextRule::judge("## Evidence\n- E1. output", $body)->verdict);
    }

    public function testATaskOf256KilobytesWithLongCodeSpansIsRead(): void
    {
        $task = "## Evidence\n- E1. `".str_repeat('x', 128 * 1024).'` and `'.str_repeat('y', 128 * 1024)."\n- E2. b";

        self::assertSame([2], PullRequestTextRule::judge($task, 'E1')->missingItems);
    }

    public function testABackslashEscapedBacktickOpensNoCodeSpan(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", 'Output: \` <!-- E1 --> `')->missingItems);
    }

    public function testACodeSpanEndsAtAHeadingLine(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", "Output: `\n## Heading\n<!-- E1 -->\n`")->missingItems);
    }

    public function testACodeSpanEndsAtAFenceLine(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- E1. output", "Output: `\n```\nx\n```\n<!-- E1 -->\n`")->missingItems);
    }

    public function testAReferenceInAFenceOpenedOnAListMarkerCounts(): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- E1. output", "- ```\n  <!-- E1 -->")->missingItems);
    }

    #[TestWith(['```'], 'backticks')]
    #[TestWith(['~~~'], 'tildes')]
    public function testAReferenceInATabIndentedFenceAtTheContentColumnOfATwoDigitItemCounts(string $fence): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- E1. output", "10. Example\n\n\t{$fence}\n\t<!-- E1 -->\n\t{$fence}")->missingItems);
    }

    public function testAListMarkerShownInsideAClosedFenceStartsNoItemForTheNextFence(): void
    {
        $body = "10. Example\n\n    ~~~\n    - prose\n      ~~~\n\n      ~~~\n    <!-- E1 -->\n    ~~~";

        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- E1. output", $body)->missingItems);
    }

    public function testAListMarkerHiddenInAnHtmlCommentStartsNoItemForTheNextFence(): void
    {
        $body = "10. Example\n\n    <!--\n    - hidden\n    -->\n\n      ~~~\n    <!-- E1 -->\n    ~~~";

        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- E1. output", $body)->missingItems);
    }

    public function testEachVerdictHasAFixedReason(): void
    {
        self::assertSame(
            [
                'the pull request text references every top-level Evidence item of the task and its body is within the cap',
                'the pull request text does not reference every top-level Evidence item of the task',
                'the pull request body is over the cap',
            ],
            array_map(static fn (PullRequestTextVerdict $verdict): string => $verdict->reason(), PullRequestTextVerdict::cases()),
        );
    }

    public function testAFenceFiveSpacesAfterAMarkerIsIndentedCodeSoAHiddenReferenceStaysHidden(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- Show the output.", "-     ~~~\n\n     <!-- E1 -->")->missingItems);
    }

    public function testAnOrderedItemNotNumberedOneAfterAParagraphLineOpensNoFenceInAnItem(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- Show the output.", "text\n10. x\n    ~~~\n    <!-- E1 -->\n    ~~~")->missingItems);
    }

    public function testAnOrderedItemNotNumberedOneAfterABlankLineStillOpensAFenceInIt(): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- Show the output.", "text\n\n10. x\n    ~~~\n    <!-- E1 -->\n    ~~~")->missingItems);
    }

    public function testAFenceAfterAnOrderedMarkerThatContinuesAParagraphOpensNothing(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- Show the output.", "A paragraph\n2. ~~~\n   <!-- E1 -->")->missingItems);
    }

    public function testAHeadingOutdentedFromAnItemEndsTheListSoTheNextEvidenceHeadingIsTheSection(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("- Example\n## Notes\n  ## Evidence\n- Show the result.", 'Done')->missingItems);
    }

    public function testAnOrderedItemAfterAOneLineCommentOpensItsFence(): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- Show the output.", "<!-- note -->\n10. Example\n\n\t~~~\n\t<!-- E1 -->\n\t~~~")->missingItems);
    }

    public function testACommentIndentedFourSpacesUnderAParagraphContinuesItSoTheNextOrderedMarkerOpensNoFence(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- Show the result.", "A paragraph\n    <!-- note -->\n10. Example\n    ~~~\n    <!-- E1 -->")->missingItems);
    }

    public function testACommentOpeningItsLineIsABlockWithTheTextAfterItSoTheNextOrderedItemOpensItsFence(): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- Show the result.", "<!-- note --> lead-in\n10. ~~~\n    <!-- E1 -->\n    ~~~")->missingItems);
    }

    public function testAnOrderedItemAfterACommentWithTextOnItsLineIsAnEvidenceItem(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n<!-- note --> lead-in\n10. Show the result.", 'Done')->missingItems);
    }

    public function testAnEmptyMarkerUnderAThematicBreakIsAnItemNotASetextUnderline(): void
    {
        self::assertSame([2], PullRequestTextRule::judge("## Evidence\n- First\n\n---\n-\n  Second", 'E1')->missingItems);
    }

    public function testAReferenceInAFenceInfoStringDoesNotCount(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- Show the result.", "~~~ E1\nunrelated\n~~~")->missingItems);
    }

    public function testAReferenceOnAFenceContentLineStillCounts(): void
    {
        self::assertSame([], PullRequestTextRule::judge("## Evidence\n- Show the result.", "~~~ text\nE1 shown here\n~~~")->missingItems);
    }

    public function testAReferenceInTheInfoStringOfAFenceAfterAMarkerDoesNotCount(): void
    {
        self::assertSame([1], PullRequestTextRule::judge("## Evidence\n- Show the result.", "- ~~~ E1\n  unrelated\n  ~~~")->missingItems);
    }

    public function testABodyWithoutReferencesIsRefusedAfterAnOutdentedComment(): void
    {
        self::assertFalse(PullRequestTextRule::judge("- Example\n<!-- note -->\n  ## Evidence\n- Show the result.", 'Done')->passed());
    }
}
