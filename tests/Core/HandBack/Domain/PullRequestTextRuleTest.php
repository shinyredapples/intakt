<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use Intakt\Core\HandBack\Domain\EvidenceItems;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PullRequestTextRule::class)]
#[CoversClass(PullRequestTextOutcome::class)]
#[CoversClass(PullRequestTextVerdict::class)]
#[UsesClass(EvidenceItems::class)]
#[UsesClass(MarkdownText::class)]
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

    public function testAMissingReferenceIsReportedBeforeABodyOverTheCap(): void
    {
        $outcome = PullRequestTextRule::judge(self::TASK, 'E1 E2 '.str_repeat('a', PullRequestTextRule::MAX_BODY_CHARACTERS));

        self::assertSame([PullRequestTextVerdict::MissingEvidenceReference, [3]], [$outcome->verdict, $outcome->missingItems]);
        self::assertGreaterThan(PullRequestTextRule::MAX_BODY_CHARACTERS, $outcome->bodyCharacters);
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
}
