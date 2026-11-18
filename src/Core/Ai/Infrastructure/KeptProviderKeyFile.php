<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeptProviderKeyNotWritten;
use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\ProviderKeyAlreadyKept;
use Intakt\Plugin\Vendor\Contract\ProviderCatalog;
use JsonException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// The data volume holds the one key a person typed in the browser, because the file is readable by the app user only.
final readonly class KeptProviderKeyFile implements KeptProviderKeys
{
    private const string FILE = 'provider-key.json';

    public function __construct(
        #[Autowire(param: AiServices::KEPT_KEY_DIRECTORY)]
        private string $directory,
        private ProviderCatalog $providers,
    ) {
    }

    // The file is 0600 before the key goes in, so others never read it.
    public function keep(ProviderKey $key): void
    {
        $filesystem = new Filesystem();
        $written = null;

        // A failure is replaced, not wrapped, because the failed call's arguments in its trace hold the key.
        try {
            $filesystem->mkdir($this->directory, 0o700);
            $written = $filesystem->tempnam($this->directory, '.'.self::FILE.'.');
            $filesystem->chmod($written, 0o600);
            // Also as kind, the field earlier releases read, so a rollback still finds the key.
            $filesystem->appendToFile($written, json_encode(['provider' => $key->provider(), 'kind' => $key->provider(), 'key' => $key->secret()], JSON_THROW_ON_ERROR));
            $this->linkWhereNothingIs($written);
        } catch (IOException|JsonException) {
            throw new KeptProviderKeyNotWritten();
        } finally {
            if (null !== $written) {
                $filesystem->remove($written);
            }
        }
    }

    public function read(): ?ProviderKey
    {
        $file = $this->file();

        if (!is_file($file)) {
            return null;
        }

        $json = file_get_contents($file);

        if (false === $json) {
            throw new KeptProviderKeyUnreadable();
        }

        return $this->parsed($json);
    }

    public function configured(): ?ConfiguredKey
    {
        return $this->read()?->configured();
    }

    // A link fails when anything is at the path, so only one of two racing requests keeps its key; PHP may resolve a dangling link there to its target, so a link is refused first.
    private function linkWhereNothingIs(string $written): void
    {
        if (is_link($this->file())) {
            throw new ProviderKeyAlreadyKept();
        }

        set_error_handler(static fn (): bool => true);

        try {
            $linked = link($written, $this->file());
        } finally {
            restore_error_handler();
        }

        if ($linked) {
            return;
        }

        if (file_exists($this->file())) {
            throw new ProviderKeyAlreadyKept();
        }

        throw new KeptProviderKeyNotWritten();
    }

    private function file(): string
    {
        return $this->directory.'/'.self::FILE;
    }

    private function parsed(#[SensitiveParameter] string $json): ProviderKey
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new KeptProviderKeyUnreadable();
        }

        // A file kept before the provider id names it as kind, with the same ids, so it still reads after an upgrade.
        $provider = is_array($data) ? ($data['provider'] ?? $data['kind'] ?? null) : null;
        $key = is_array($data) ? ($data['key'] ?? null) : null;

        if (!is_string($provider) || !is_string($key)) {
            throw new KeptProviderKeyUnreadable();
        }

        return $this->recognised($provider, $key);
    }

    // The file is outside input, so its key must still have the form of its provider; a short value would otherwise show whole as configured.
    private function recognised(string $provider, #[SensitiveParameter] string $key): ProviderKey
    {
        try {
            $providerKey = ProviderKey::recognise($key, $this->providers);
        } catch (KeyNotRecognised) {
            throw new KeptProviderKeyUnreadable();
        }

        if ($providerKey->provider() !== $provider) {
            throw new KeptProviderKeyUnreadable();
        }

        return $providerKey;
    }
}
