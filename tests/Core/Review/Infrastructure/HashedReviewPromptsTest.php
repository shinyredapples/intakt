<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\PromptHashes;
use Intakt\Core\Review\Infrastructure\HashedReviewPrompts;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HashedReviewPrompts::class)]
#[CoversClass(PromptHashes::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(JudgePrompt::class)]
final class HashedReviewPromptsTest extends TestCase
{
    public function testTheHashesAreTheSameOnTwoReads(): void
    {
        $prompts = new HashedReviewPrompts();

        self::assertEquals($prompts->hashes(), new HashedReviewPrompts()->hashes());
    }

    public function testTheShippedHashesAreTheSha256OfThePromptTexts(): void
    {
        $hashes = new HashedReviewPrompts()->hashes();

        self::assertSame(hash('sha256', hash('sha256', ReviewerPrompt::INSTRUCTIONS).hash('sha256', ReviewerPrompt::TECHNICAL_WRITER_INSTRUCTIONS)), $hashes->reviewer);
        self::assertSame(hash('sha256', JudgePrompt::INSTRUCTIONS), $hashes->judge);
    }

    public function testChangingOnlyTheTechnicalWritersInstructionsChangesTheReviewerHash(): void
    {
        $shipped = new HashedReviewPrompts()->hashes();
        $changed = new HashedReviewPrompts(writer: 'Another instruction.')->hashes();

        self::assertNotSame($shipped->reviewer, $changed->reviewer);
        self::assertSame($shipped->judge, $changed->judge);
    }

    public function testMovingTextBetweenTheReviewerAndTheWritersInstructionsChangesTheReviewerHash(): void
    {
        $one = new HashedReviewPrompts('Check ab', writer: 'c')->hashes();
        $other = new HashedReviewPrompts('Check a', writer: 'bc')->hashes();

        self::assertNotSame($one->reviewer, $other->reviewer);
    }

    public function testAChangedReviewerPromptChangesOnlyTheReviewerHash(): void
    {
        $shipped = new HashedReviewPrompts()->hashes();
        $changed = new HashedReviewPrompts('Another instruction.')->hashes();

        self::assertNotSame($shipped->reviewer, $changed->reviewer);
        self::assertSame($shipped->judge, $changed->judge);
    }

    public function testAChangedJudgePromptChangesOnlyTheJudgeHash(): void
    {
        $shipped = new HashedReviewPrompts()->hashes();
        $changed = new HashedReviewPrompts(judge: 'Another instruction.')->hashes();

        self::assertNotSame($shipped->judge, $changed->judge);
        self::assertSame($shipped->reviewer, $changed->reviewer);
    }

    public function testTheReviewerAndTheJudgeHaveHashesOfTheirOwn(): void
    {
        $hashes = new HashedReviewPrompts()->hashes();

        self::assertNotSame($hashes->reviewer, $hashes->judge);
    }
}
