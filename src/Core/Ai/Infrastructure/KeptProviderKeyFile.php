<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeptProviderKey;
use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\ProviderKey;
use JsonException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

// The data volume holds the one key a person typed in the browser, because the file is readable by the app user only.
final readonly class KeptProviderKeyFile implements KeptProviderKeys
{
    private const string FILE = 'provider-key.json';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/data')]
        private string $directory,
    ) {
    }

    // A new file renamed over the path replaces a link instead of following it, because a rename never writes through one; the file is 0600 before the key goes in, so others never read it.
    public function keep(ProviderKey $key): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory, 0o700);
        $written = $filesystem->tempnam($this->directory, '.'.self::FILE.'.');
        $filesystem->chmod($written, 0o600);
        $filesystem->appendToFile($written, json_encode(['kind' => $key->kind()->value, 'key' => $key->secret()], JSON_THROW_ON_ERROR));
        $filesystem->rename($written, $this->file(), true);
    }

    public function read(): ?KeptProviderKey
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

    private function parsed(#[SensitiveParameter] string $json): KeptProviderKey
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new KeptProviderKeyUnreadable();
        }

        $kind = is_array($data) && is_string($data['kind'] ?? null) ? KeyKind::tryFrom($data['kind']) : null;
        $key = is_array($data) ? ($data['key'] ?? null) : null;

        if (!$kind instanceof KeyKind || !is_string($key) || '' === $key) {
            throw new KeptProviderKeyUnreadable();
        }

        return new KeptProviderKey($kind, $key);
    }

    private function file(): string
    {
        return $this->directory.'/'.self::FILE;
    }
}
