<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Intakt\Core\Budget\Contract\BudgetServices;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredPrices implements Prices
{
    // Named in place of a model a call's started row left out (written before the model was kept), so the cost line still says why it is unknown.
    public const string NO_MODEL = '(no model)';

    private const int TOKENS_PER_PRICE = 1_000_000;

    /**
     * @param array<string, array{input: float, output: float, cache_read: float, cache_write: float}> $prices
     */
    public function __construct(
        #[Autowire(param: BudgetServices::PRICES)]
        private array $prices,
    ) {
    }

    public function costOf(?string $model, TokenFigures $figures): Cost
    {
        $price = null === $model ? null : $this->prices[$model] ?? null;

        if (null === $price) {
            return Cost::unknown($model ?? self::NO_MODEL);
        }

        return Cost::inUsd($this->dollarsFor($price, $figures));
    }

    /**
     * @param array{input: float, output: float, cache_read: float, cache_write: float} $price
     */
    // Cached tokens a vendor does not split into reads and writes stay in the input, because which of the two prices applies is not known.
    private function dollarsFor(array $price, TokenFigures $figures): float
    {
        $input = max(0, $figures->prompt - $figures->cacheRead - $figures->cacheWrite);

        return ($input * $price['input']
            + $figures->cacheRead * $price['cache_read']
            + $figures->cacheWrite * $price['cache_write']
            + $figures->completion * $price['output']) / self::TOKENS_PER_PRICE;
    }
}
