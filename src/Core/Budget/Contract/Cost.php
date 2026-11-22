<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Closure;

// Unknown as soon as one call has no price, because a partial sum would read as the whole cost and a cost is an estimate, so it carries the date of the price list it was worked out from.
final readonly class Cost
{
    private function __construct(
        public ?float $usd,
        public UnpricedModels $unpriced,
        public ?string $priceDate = null,
    ) {
    }

    public static function none(): self
    {
        return new self(0.0, new UnpricedModels());
    }

    public static function inUsd(float $usd, ?string $priceDate = null): self
    {
        return new self($usd, new UnpricedModels(), $priceDate);
    }

    public static function unknown(string ...$unpriced): self
    {
        return new self(null, new UnpricedModels(...$unpriced));
    }

    public function plus(self $other): self
    {
        if (null === $this->usd || null === $other->usd) {
            return self::unknown(...$this->unpriced, ...$other->unpriced);
        }

        return self::inUsd($this->usd + $other->usd, $this->priceDate ?? $other->priceDate);
    }

    // The one wording of a cost, for the console and the dashboard. Unknown rather than 0, so a missing price is never read as a free round, and a priced cost too small to show is never read as free either. A model name comes from the ledger, so each surface passes how it makes a name safe to show.
    /**
     * @param Closure(string): string|null $visible
     */
    public function reading(?Closure $visible = null): string
    {
        if (null !== $this->usd) {
            $dollars = sprintf('%.4f', $this->usd);

            return '0.0000' === $dollars && $this->usd > 0.0 ? 'less than 0.0001 USD' : $dollars.' USD';
        }

        if ($this->unpriced->isEmpty()) {
            return 'unknown';
        }

        return 'unknown, no price for '.implode(', ', array_map($visible ?? static fn (string $model): string => $model, [...$this->unpriced]));
    }

    // One wording for the console and the dashboard, because an estimate is only as current as its price list and an owner should read the same day on both.
    public function priceDateReading(): string
    {
        return null === $this->priceDate ? 'price date unknown' : 'prices of '.$this->priceDate;
    }
}
