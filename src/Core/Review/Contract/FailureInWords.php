<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;

// A reviewer's failure is stored as an exception class, because a provider's message can echo a key, or as words already key-safe, which pass through; a user reads words, never a class name.
final readonly class FailureInWords
{
    private const array KNOWN = [
        MaxOutputTokensException::class => 'stopped at its output limit',
        ProviderRejectedTheKey::class => 'the provider rejected the key',
        ProviderFailedTransiently::class => 'the provider kept failing',
        ProviderUnreachable::class => 'the model endpoint could not be reached',
        VerdictUnreadable::class => VerdictUnreadable::IN_WORDS,
        ChangesWithoutFindings::class => 'gave no approval without naming a finding',
    ];

    public function of(string $failure): string
    {
        if (!class_exists($failure) && 1 !== preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $failure)) {
            return $failure;
        }

        return self::KNOWN[ltrim($failure, '\\')] ?? 'failed with an unexpected error';
    }
}
