<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\KeepProviderKey;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use NoDiscard;
use SensitiveParameter;

final readonly class KeepProviderKey
{
    public function __construct(
        private KeyCheck $keyCheck,
        private KeptProviderKeys $kept,
    ) {
    }

    /**
     * @throws KeyNotRecognised
     * @throws KeyRefusedByProvider
     * @throws KeyNotChecked
     */
    #[NoDiscard('the configured key is what the page shows')]
    public function keep(#[SensitiveParameter] string $key): ConfiguredKey
    {
        $providerKey = ProviderKey::recognise($key);

        return match ($this->keyCheck->check($providerKey)) {
            KeyCheckOutcome::Accepted => $this->kept($providerKey),
            KeyCheckOutcome::Refused => throw new KeyRefusedByProvider(),
            KeyCheckOutcome::NotChecked => throw new KeyNotChecked(),
        };
    }

    /**
     * @throws KeptProviderKeyUnreadable
     */
    public function configured(): ?ConfiguredKey
    {
        return $this->kept->configured();
    }

    // Kept only after the check accepted it, so a refused or unchecked key never reaches the disk.
    private function kept(ProviderKey $providerKey): ConfiguredKey
    {
        $this->kept->keep($providerKey);

        return $providerKey->configured();
    }
}
