<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\KeepProviderKey;

use Intakt\Core\Ai\Contract\ConfiguredKey;
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
            KeyCheckOutcome::Accepted => $providerKey->configured(),
            KeyCheckOutcome::Refused => throw new KeyRefusedByProvider(),
            KeyCheckOutcome::NotChecked => throw new KeyNotChecked(),
        };
    }
}
