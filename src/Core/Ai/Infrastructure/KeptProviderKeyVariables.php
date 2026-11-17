<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\Vendor\Contract\ProviderCatalog;
use Intakt\Plugin\Vendor\Contract\ProviderDescriptor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

// Symfony's env processor asks its loaders only for a name the environment lacks and takes the first non-empty value in priority order, so below the vault's loader (priority 0) a value in `.env.local` or the vault wins and the kept key is used like the same variable.
#[AsTaggedItem(priority: self::PRIORITY)]
final readonly class KeptProviderKeyVariables implements EnvVarLoaderInterface
{
    public const int PRIORITY = -100;

    public function __construct(
        private KeptProviderKeys $keys,
        private ProviderCatalog $providers,
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

        $descriptor = $this->providers->descriptor($key->provider());
        $variable = array_first($descriptor?->variables() ?? []);

        if (!$descriptor instanceof ProviderDescriptor || null === $variable) {
            return [];
        }

        return [$variable => $key->secret(), ...$this->compatibleStandIn($descriptor, $key)];
    }

    // As the image's entrypoint does for HF_TOKEN, because the models of such a key are mapped on the OpenAI compatible endpoint, which a kept key would otherwise never turn on; an endpoint the owner set keeps theirs, so the key is never sent to it.
    /**
     * @return array<string, string>
     */
    private function compatibleStandIn(ProviderDescriptor $descriptor, ProviderKey $key): array
    {
        $compatible = Provider::OpenAiCompatible->variables();

        if (Provider::OpenAiCompatible !== $descriptor->tierProvider() || [] !== array_intersect($descriptor->variables(), $compatible) || array_any($compatible, $this->isSetInTheEnvironment(...))) {
            return [];
        }

        return array_combine($compatible, [$descriptor->baseUrl()->toString(), $key->secret()]);
    }

    private function isSetInTheEnvironment(string $name): bool
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && '' !== $value;
    }
}
