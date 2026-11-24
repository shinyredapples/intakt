<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use RuntimeException;
use SodiumException;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\EnvVarProcessor;

// Same sources as Symfony's env processor: the environment, then the secrets vault, then the kept key. Presence is decided at build time because an unset env reference fails when the service is built.
final readonly class ProviderKeySource
{
    /** @var array<string, mixed> */
    private array $vaultNames;

    /**
     * @param array<string, string>        $keptValues
     * @param Closure(string): (?string)   $environment
     */
    public function __construct(
        private SodiumVault $vault,
        private array $keptValues,
        private Closure $environment,
    ) {
        // Names only, since decrypting every entry up front would let an unrelated secret abort the boot; the framework's env loader reveals one name at a time too.
        $this->vaultNames = $vault->list();
    }

    public static function environmentValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }

    // The framework's default vault directory and decryption key (the secret when set, else the vault's own private key file), which config/packages/framework.yaml keeps.
    public static function openVault(string $directory, ?string $decryptionSecret): SodiumVault
    {
        $secret = $decryptionSecret ?? '';
        // Decoded by the framework's own base64: env processor (lenient, either alphabet), so a key the framework accepts is not missing here.
        $decryptionKey = new EnvVarProcessor(new Container())->getEnv('base64', 'SYMFONY_DECRYPTION_SECRET', static fn (): string => $secret);

        return new SodiumVault($directory, is_string($decryptionKey) ? $decryptionKey : null);
    }

    // Revealed because an empty secret is as unusable as a missing one; a secret that cannot be decrypted would fail when the service is built.
    public function isSet(string $name): bool
    {
        if (null !== ($this->environment)($name)) {
            return true;
        }

        $fromVault = $this->vaultValue($name);
        // The kept key must not enable the provider here: resolving the variable would throw in the vault's loader before the kept key is reached.
        if (false === $fromVault) {
            return false;
        }

        return '' !== ($fromVault ?? '') || '' !== ($this->keptValues[$name] ?? '');
    }

    // False for a listed secret whose reveal throws, apart from null for one the vault lacks or cannot open, because the vault's loader throws the same way at run time before any later loader is asked.
    private function vaultValue(string $name): string|false|null
    {
        if (!array_key_exists($name, $this->vaultNames)) {
            return null;
        }

        try {
            return $this->vault->reveal($name);
        } catch (SodiumException|RuntimeException) {
            // A decryption key of the wrong length, or no key at all, throws where a wrong key only returns null; either way the secret is unusable, so it never counts as set.
            return false;
        }
    }
}
