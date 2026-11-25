<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Contract;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\TokenUsageExtractor as AnthropicUsage;
use Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor as ClaudeCodeUsage;
use Symfony\AI\Platform\Bridge\Generic\Completions\TokenUsageExtractor as OpenAiFormUsage;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

#[CoversClass(TokenUsageRow::class)]
#[CoversClass(TokenFigures::class)]
#[CoversClass(Account::class)]
#[CoversClass(Provider::class)]
final class TokenUsageRowTest extends TestCase
{
    // The usage block of a recorded Anthropic answer, because there a cached prompt leaves input_tokens at a few tokens and the vendor reports no total.
    private const array ANTHROPIC_USAGE = ['input_tokens' => 4, 'cache_creation_input_tokens' => 1500, 'cache_read_input_tokens' => 38000, 'output_tokens' => 700];

    public function testAnAnthropicPromptCountsItsCacheReadsAndWritesEachShownApart(): void
    {
        $figures = $this->rowOf(new AnthropicUsage()->extractFromArray(self::ANTHROPIC_USAGE), 'anthropic.default')->figures();

        self::assertEquals(new TokenFigures(39504, 38000, 1500, 0, 700), $figures);
    }

    public function testTheTotalIsThePromptAndCompletionSummedWhenTheVendorReportsNoTotal(): void
    {
        $figures = $this->rowOf(new AnthropicUsage()->extractFromArray(self::ANTHROPIC_USAGE), 'anthropic.default')->figures();

        self::assertSame(40204, $figures->total());
    }

    public function testAClaudeCodePromptCountsItsCachedTokensThatTheBridgeDoesNotSplit(): void
    {
        $answer = new InMemoryRawResult(['result' => 'done', 'usage' => self::ANTHROPIC_USAGE]);

        $figures = $this->rowOf(new ClaudeCodeUsage()->extract($answer), 'claude_code.work')->figures();

        self::assertEquals(new TokenFigures(39504, 0, 0, 39500, 700), $figures);
    }

    public function testAnAnthropicRowWithCachedAndOnlyACacheReadCountsTheRemainderAsUnsplitAndInThePrompt(): void
    {
        $row = new TokenUsageRow('anthropic.default', null, 4, 700, null, null, 39500, null, 38000, null, null, null, null);

        self::assertEquals(new TokenFigures(39504, 38000, 0, 1500, 700), $row->figures());
    }

    public function testAClaudeCodeRowWithCachedAndOnlyACacheWriteCountsTheRemainderAsUnsplitAndInThePrompt(): void
    {
        $row = new TokenUsageRow('claude_code.work', null, 4, 700, null, null, 39500, 1500, null, null, null, null, null);

        self::assertEquals(new TokenFigures(39504, 0, 1500, 38000, 700), $row->figures());
    }

    public function testARowWhoseSplitFiguresExceedCachedCountsNoNegativeUnsplitFigure(): void
    {
        $row = new TokenUsageRow('anthropic.default', null, 4, 700, null, null, 100, null, 200, null, null, null, null);

        self::assertEquals(new TokenFigures(204, 200, 0, 0, 700), $row->figures());
    }

    public function testAnOpenAiFormPromptAlreadyHoldsItsCachedReadsSoTheyAreNotAddedAgain(): void
    {
        $usage = ['prompt_tokens' => 40000, 'prompt_tokens_details' => ['cached_tokens' => 38000], 'completion_tokens' => 700, 'total_tokens' => 40700];

        $figures = $this->rowOf(new OpenAiFormUsage()->extractFromArray($usage), 'openai_compatible.default')->figures();

        self::assertEquals(new TokenFigures(40000, 38000, 0, 0, 700), $figures);
    }

    public function testTheTotalIsTheSumEvenWhenTheVendorTotalSaysOtherwise(): void
    {
        $usage = ['prompt_tokens' => 40000, 'completion_tokens' => 700, 'total_tokens' => 0];

        $figures = $this->rowOf(new OpenAiFormUsage()->extractFromArray($usage), 'openai.default')->figures();

        self::assertSame(40700, $figures->total());
    }

    public function testARowWithoutAnAccountKeepsThePromptTheVendorReported(): void
    {
        $figures = $this->rowOf(new AnthropicUsage()->extractFromArray(self::ANTHROPIC_USAGE), null)->figures();

        self::assertEquals(new TokenFigures(4, 38000, 1500, 0, 700), $figures);
    }

    public function testARowWhoseAccountIsNoAccountKeyKeepsThePromptTheVendorReported(): void
    {
        $figures = $this->rowOf(new AnthropicUsage()->extractFromArray(self::ANTHROPIC_USAGE), 'anthropic')->figures();

        self::assertSame(4, $figures->prompt);
    }

    public function testARowWhoseAccountNamesAnUnknownProviderKeepsThePromptTheVendorReported(): void
    {
        $figures = $this->rowOf(new AnthropicUsage()->extractFromArray(self::ANTHROPIC_USAGE), 'elsewhere.default')->figures();

        self::assertSame(4, $figures->prompt);
    }

    public function testFiguresAddUpFieldByField(): void
    {
        $sum = new TokenFigures(10, 1, 2, 3, 4)->plus(new TokenFigures(20, 10, 20, 30, 40));

        self::assertEquals(new TokenFigures(30, 11, 22, 33, 44), $sum);
    }

    // The fields UsageLedger copies from a vendor's usage, so the row is the one a recorded answer leaves in the ledger.
    private function rowOf(?TokenUsageInterface $usage, ?string $account): TokenUsageRow
    {
        self::assertNotNull($usage);

        return new TokenUsageRow(
            $account,
            $usage->getModel(),
            $usage->getPromptTokens(),
            $usage->getCompletionTokens(),
            $usage->getThinkingTokens(),
            $usage->getToolTokens(),
            $usage->getCachedTokens(),
            $usage->getCacheCreationTokens(),
            $usage->getCacheReadTokens(),
            $usage->getTotalTokens(),
            $usage->getRemainingTokens(),
            $usage->getRemainingTokensMinute(),
            $usage->getRemainingTokensMonth(),
        );
    }
}
