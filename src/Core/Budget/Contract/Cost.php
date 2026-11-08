<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// Unknown as soon as one call has no price, because a partial sum would read as the whole cost and the design never shows an estimate.
final readonly class Cost
{
    /**
     * @param list<string> $unpriced
     */
    private function __construct(
        public ?float $usd,
        public array $unpriced,
    ) {
    }

    public static function none(): self
    {
        return new self(0.0, []);
    }

    public static function inUsd(float $usd): self
    {
        return new self($usd, []);
    }

    public static function unknown(string ...$unpriced): self
    {
        return new self(null, array_values(array_unique($unpriced)));
    }

    public function plus(self $other): self
    {
        if (null === $this->usd || null === $other->usd) {
            return self::unknown(...$this->unpriced, ...$other->unpriced);
        }

        return self::inUsd($this->usd + $other->usd);
    }
}
