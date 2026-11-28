<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerPrompt::class)]
#[UsesClass(Finding::class)]
final class ReviewerPromptVerdictTest extends TestCase
{
    public function testTheReviewerPromptAsksForApproveOrChangesOnly(): void
    {
        self::assertStringContainsString('You answer with a verdict: approve or changes.', ReviewerPrompt::INSTRUCTIONS);
        self::assertStringNotContainsStringIgnoringCase('blocked', ReviewerPrompt::INSTRUCTIONS);
    }

    public function testTheReviewerPromptGivesAStoppingProblemAsAP1Finding(): void
    {
        self::assertStringContainsString('a finding with severity P1, never a verdict of its own', ReviewerPrompt::INSTRUCTIONS);
    }

    // VerdictParserTest proves this example passes the title's rules, so the prompt cannot teach a title the parser refuses.
    public function testTheReviewerPromptGivesTheFindingsOwnTitleExample(): void
    {
        self::assertStringContainsString('(for example "'.Finding::TITLE_EXAMPLE.'")', ReviewerPrompt::INSTRUCTIONS);
        self::assertStringContainsString("given.\n\nEvery finding names the file and line", ReviewerPrompt::INSTRUCTIONS);
    }

    public function testTheRetryForMissingFindingsAsksForApproveOrChangesOnly(): void
    {
        self::assertStringNotContainsStringIgnoringCase('blocked', ReviewerPrompt::NAME_FINDINGS_OR_APPROVE);
    }
}
