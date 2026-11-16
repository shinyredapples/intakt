<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

// Symfony's env processor asks its loaders only for a name the environment lacks, so a value in `.env.local` wins and the kept key is used like the same variable.
// It takes the first loader's non-empty value in priority order, so this one sits below the secrets vault's loader (priority 0) and a vault secret wins too.
#[AsTaggedItem(priority: self::PRIORITY)]
final readonly class KeptProviderKeyVariables implements EnvVarLoaderInterface
{
    public const int PRIORITY = -100;

    private const string HUGGING_FACE_VARIABLE = 'HF_TOKEN';

    public function __construct(
        private KeptProviderKeys $keys,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function loadEnvVars(): array
    {
        // An unusable file must not stop the boot or a request; the fixed message holds no file content.
        try {
            $key = $this->keys->read();
        } catch (KeptProviderKeyUnreadable) {
            $this->logger?->warning('The kept provider key could not be read, so it is not used.');

            return [];
        }

        if (!$key instanceof ProviderKey) {
            return [];
        }

        return [self::variableOf($key->kind()) => $key->secret()];
    }

    public static function variableOf(KeyKind $kind): string
    {
        return match ($kind) {
            KeyKind::Anthropic => Provider::Anthropic->variables()[0],
            KeyKind::OpenAi => Provider::OpenAi->variables()[0],
            KeyKind::HuggingFace => self::HUGGING_FACE_VARIABLE,
        };
    }
}
