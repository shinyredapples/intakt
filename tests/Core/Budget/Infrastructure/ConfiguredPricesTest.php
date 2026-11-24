<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\TokenUsageExtractor as AnthropicUsage;
use Symfony\AI\Platform\Bridge\Generic\Completions\TokenUsageExtractor as OpenAiFormUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

#[CoversClass(ConfiguredPrices::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenUsageRow::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
final class ConfiguredPricesTest extends TestCase
{
    // The usage block of a recorded Anthropic answer, because a cached prompt there splits into uncached input, cache reads and cache writes, each with its own price.
    private const array ANTHROPIC_USAGE = ['input_tokens' => 4, 'cache_creation_input_tokens' => 1500, 'cache_read_input_tokens' => 38000, 'output_tokens' => 700];

    private const array OPUS = ['input' => 5.0, 'output' => 25.0, 'cache_read' => 0.5, 'cache_write' => 6.25];

    public function testAPricedCostCarriesTheDayOfThePriceList(): void
    {
        $cost = new ConfiguredPrices(['claude-opus-5-5' => self::OPUS], '2026-11-13')->costOf('claude-opus-5-5', new TokenFigures(100, 0, 0, 0, 20));

        self::assertSame('2026-11-13', $cost->priceDate);
    }

    public function testARecordedAnthropicAnswerCostsItsTokensAtTheModelsPricePerMillion(): void
    {
        $row = $this->rowOf(new AnthropicUsage()->extractFromArray(self::ANTHROPIC_USAGE), 'anthropic.default', 'claude-opus-5-5');

        $cost = new ConfiguredPrices(['claude-opus-5-5' => self::OPUS])->costOf($row->model, $row->figures());

        self::assertEqualsWithDelta(0.045895, $cost->usd, 1e-12);
    }

    public function testARecordedAnswerOfAModelWithoutAPriceIsUnknownAndNotZero(): void
    {
        $row = $this->rowOf(new AnthropicUsage()->extractFromArray(self::ANTHROPIC_USAGE), 'anthropic.default', 'claude-haiku-5-5');

        $cost = new ConfiguredPrices(['claude-opus-5-5' => self::OPUS])->costOf($row->model, $row->figures());

        self::assertSame([null, ['claude-haiku-5-5']], [$cost->usd, [...$cost->unpriced]]);
    }

    public function testAnOpenAiFormAnswerPaysTheCacheReadPriceOnlyForTheCachedPartOfItsPrompt(): void
    {
        $usage = ['prompt_tokens' => 40000, 'prompt_tokens_details' => ['cached_tokens' => 38000], 'completion_tokens' => 700, 'total_tokens' => 40700];
        $row = $this->rowOf(new OpenAiFormUsage()->extractFromArray($usage), 'openai.default', 'gpt-5');

        $cost = new ConfiguredPrices(['gpt-5' => ['input' => 1.0, 'output' => 10.0, 'cache_read' => 0.1, 'cache_write' => 1.0]])->costOf($row->model, $row->figures());

        self::assertEqualsWithDelta((2000 * 1.0 + 38000 * 0.1 + 700 * 10.0) / 1_000_000, $cost->usd, 1e-12);
    }

    public function testCachedTokensAVendorDoesNotSplitIntoReadsAndWritesPayTheInputPrice(): void
    {
        $cost = new ConfiguredPrices(['claude-opus-5-5' => self::OPUS])->costOf('claude-opus-5-5', new TokenFigures(39504, 0, 0, 39500, 700));

        self::assertEqualsWithDelta((39504 * 5.0 + 700 * 25.0) / 1_000_000, $cost->usd, 1e-12);
    }

    public function testAUsageRowThatNamesNoModelHasAnUnknownCost(): void
    {
        $cost = new ConfiguredPrices(['claude-opus-5-5' => self::OPUS])->costOf(null, new TokenFigures(100, 0, 0, 0, 20));

        self::assertSame([null, [ConfiguredPrices::NO_MODEL]], [$cost->usd, [...$cost->unpriced]]);
    }

    // The fields UsageLedger copies from a vendor's usage, so the row is the one a recorded answer leaves in the ledger.
    private function rowOf(?TokenUsageInterface $usage, string $account, string $model): TokenUsageRow
    {
        self::assertNotNull($usage);

        return new TokenUsageRow(
            $account,
            $model,
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
