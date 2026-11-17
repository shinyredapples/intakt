<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;
use SensitiveParameterValue;

final readonly class ProviderKey
{
    private const int SHOWN_AT_MOST = 4;

    // The key sits in a SensitiveParameterValue so var_dump, print_r and var_export show an empty object instead of it.
    private SensitiveParameterValue $key;

    private function __construct(
        private KeyKind $kind,
        #[SensitiveParameter]
        string $key,
    ) {
        $this->key = new SensitiveParameterValue($key);
    }

    public static function recognise(#[SensitiveParameter] string $key): self
    {
        $key = trim($key);
        $kind = KeyKind::fromForm($key);

        if (!$kind instanceof KeyKind) {
            throw new KeyNotRecognised();
        }

        return new self($kind, $key);
    }

    public function kind(): KeyKind
    {
        return $this->kind;
    }

    // Handed out because the check call must send the key to its provider and the kept file must hold it; the key goes nowhere else.
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
