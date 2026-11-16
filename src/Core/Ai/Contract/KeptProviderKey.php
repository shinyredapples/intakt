<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;
use SensitiveParameterValue;

// A key read back from the data volume; like ProviderKey it shows nothing of itself to var_dump or a trace.
final readonly class KeptProviderKey
{
    private const int SHOWN_AT_MOST = 4;

    private SensitiveParameterValue $key;

    public function __construct(
        private KeyKind $kind,
        #[SensitiveParameter]
        string $key,
    ) {
        $this->key = new SensitiveParameterValue($key);
    }

    public function kind(): KeyKind
    {
        return $this->kind;
    }

    // Handed out for the one caller that puts the key into a provider variable; it goes nowhere else.
    public function secret(): string
    {
        return $this->key->getValue();
    }

    public function configured(): ConfiguredKey
    {
        $key = $this->key->getValue();
        // A quarter of the key at most, so a short key never shows more than a small part of itself.
        $shown = min(self::SHOWN_AT_MOST, intdiv(strlen($key), 4));

        return new ConfiguredKey($this->kind, substr($key, -$shown));
    }
}
