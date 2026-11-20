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
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Throwable;

// Symfony's env processor asks its loaders only for a name the environment lacks and takes the first non-empty value in priority order, so below the vault's loader (priority 0) a value in `.env.local` or the vault wins and the kept key is used like the same variable.
#[AsTaggedItem(priority: self::PRIORITY)]
final readonly class KeptProviderKeyVariables implements EnvVarLoaderInterface
{
    public const int PRIORITY = -100;

    /**
     * @param iterable<EnvVarLoaderInterface> $otherLoaders
     */
    public function __construct(
        private KeptProviderKeys $keys,
        private ProviderCatalog $providers,
        private ?LoggerInterface $logger = null,
        // The env processor pairs each variable on its own, so a base URL from the vault would go with the kept key unless every other source is asked here too.
        #[AutowireIterator('container.env_var_loader')]
        private iterable $otherLoaders = [],
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

    // As the image's entrypoint does for HF_TOKEN, because the models of such a key are mapped on the OpenAI compatible endpoint, which a kept key would otherwise never turn on; an endpoint or key the owner set in any source keeps theirs, so the kept key is never sent to their endpoint and their key never to the router.
    /**
     * @return array<string, string>
     */
    private function compatibleStandIn(ProviderDescriptor $descriptor, ProviderKey $key): array
    {
        $compatible = Provider::OpenAiCompatible->variables();

        if (Provider::OpenAiCompatible !== $descriptor->tierProvider() || [] !== array_intersect($descriptor->variables(), $compatible) || array_any($compatible, $this->isSetElsewhere(...))) {
            return [];
        }

        return array_combine($compatible, [$descriptor->baseUrl()->toString(), $key->secret()]);
    }

    private function isSetElsewhere(string $name): bool
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (is_string($value) && '' !== $value) {
            return true;
        }

        foreach ($this->otherLoaders as $loader) {
            if ($loader !== $this && $this->loads($loader, $name)) {
                return true;
            }
        }

        return false;
    }

    // A value that cannot be read, such as a vault secret that fails to decrypt, counts as set, because the env processor would fail on it rather than fall through to the kept key.
    private function loads(EnvVarLoaderInterface $loader, string $name): bool
    {
        try {
            $value = $loader->loadEnvVars()[$name] ?? null;

            return null !== $value && '' !== (string) $value;
            // Boundary: keeps a failing foreign loader from stopping the boot or a request, and logs it so the kept key is never paired with the owner's endpoint unnoticed.
        } catch (Throwable $failure) {
            $this->logger?->warning('Another env loader failed, so its variable counts as set and the kept key does not stand in for it.', ['loader' => $loader::class, 'exception_class' => $failure::class]);

            return true;
        }
    }
}
