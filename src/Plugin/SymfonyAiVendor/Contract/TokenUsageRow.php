<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Contract;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;

// A value the vendor did not report stays null in the JSON, because usage rows have always kept the key.
final readonly class TokenUsageRow
{
    public function __construct(
        public ?string $account,
        public ?string $model,
        public ?int $prompt,
        public ?int $completion,
        public ?int $thinking,
        public ?int $tool,
        public ?int $cached,
        public ?int $cacheCreation,
        public ?int $cacheRead,
        public ?int $total,
        public ?int $remaining,
        public ?int $remainingMinute,
        public ?int $remainingMonth,
    ) {
    }

    public function figures(): TokenFigures
    {
        if (true === $this->provider()?->countsCacheApartFromPrompt()) {
            return $this->figuresWithCacheApart();
        }

        return new TokenFigures($this->prompt ?? 0, $this->cacheRead ?? $this->cached ?? 0, $this->cacheCreation ?? 0, 0, $this->completion ?? 0);
    }

    // A row without a known provider keeps the prompt as reported, because adding its cache could count it twice.
    private function provider(): ?Provider
    {
        return null === $this->account ? null : Account::providerOfKey($this->account);
    }

    private function figuresWithCacheApart(): TokenFigures
    {
        $read = $this->cacheRead ?? 0;
        $write = $this->cacheCreation ?? 0;
        // Floored at zero, because a vendor whose split figures exceed its combined figure must not shrink the prompt.
        $unsplit = max(0, ($this->cached ?? 0) - $read - $write);

        return new TokenFigures(($this->prompt ?? 0) + $read + $write + $unsplit, $read, $write, $unsplit, $this->completion ?? 0);
    }
}
