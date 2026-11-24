<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use Intakt\Plugin\Vendor\Contract\ProviderCatalog;
use Intakt\Plugin\Vendor\Contract\ProviderDescriptor;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class ProviderKey
{
    private const int SHOWN_AT_MOST = 4;

    // The key sits in a SensitiveParameterValue so var_dump, print_r and var_export show an empty object instead of it.
    private SensitiveParameterValue $key;

    /**
     * @param non-empty-string $provider
     */
    private function __construct(
        private string $provider,
        #[SensitiveParameter]
        string $key,
    ) {
        $this->key = new SensitiveParameterValue($key);
    }

    /**
     * @throws KeyNotRecognised
     */
    public static function recognise(#[SensitiveParameter] string $key, ProviderCatalog $catalog): self
    {
        $key = trim($key);
        $fitting = array_values(array_filter($catalog->descriptors(), static fn (ProviderDescriptor $descriptor): bool => 1 === preg_match($descriptor->keyPattern(), $key)));

        // Exactly one, so a key two forms fit is refused instead of going to whichever provider is listed first.
        if (1 !== count($fitting)) {
            throw new KeyNotRecognised(...array_map(static fn (ProviderDescriptor $descriptor): string => $descriptor->label(), $catalog->descriptors()));
        }

        return new self($fitting[0]->id(), $key);
    }

    /**
     * @return non-empty-string
     */
    public function provider(): string
    {
        return $this->provider;
    }

    // Handed out because the check call must send the key to its provider and the kept file must hold it; the key goes nowhere else.
    public function secret(): string
    {
        return $this->key->getValue();
    }

    public function configured(): ConfiguredKey
    {
        return new ConfiguredKey($this->provider, $this->lastCharacters());
    }

    private function lastCharacters(): string
    {
        $key = $this->key->getValue();
        // A quarter of the key at most, so a short key never shows more than a small part of itself.
        $shown = min(self::SHOWN_AT_MOST, intdiv(strlen($key), 4));

        // Checked apart because substr($key, -0) returns the whole key instead of none of it.
        if (0 === $shown) {
            return '';
        }

        return substr($key, -$shown);
    }
}
