<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeptProviderKeyNotWritten;
use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\ProviderKeyAlreadyKept;
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
            $filesystem->appendToFile($written, json_encode(['kind' => $key->kind()->value, 'key' => $key->secret()], JSON_THROW_ON_ERROR));
            $this->linkWhereNothingIs($written);
        } catch (IOException|JsonException) {
            throw new KeptProviderKeyNotWritten();
        } finally {
            if (null !== $written) {
                $filesystem->remove($written);
            }
        }
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

    private function parsed(#[SensitiveParameter] string $json): ProviderKey
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new KeptProviderKeyUnreadable();
        }

        $kind = is_array($data) && is_string($data['kind'] ?? null) ? KeyKind::tryFrom($data['kind']) : null;
        $key = is_array($data) ? ($data['key'] ?? null) : null;

        if (!$kind instanceof KeyKind || !is_string($key)) {
            throw new KeptProviderKeyUnreadable();
        }

        return $this->recognised($kind, $key);
    }

    // The file is outside input, so its key must still have the form of its kind; a short value would otherwise show whole as configured.
    private function recognised(KeyKind $kind, #[SensitiveParameter] string $key): ProviderKey
    {
        try {
            $providerKey = ProviderKey::recognise($key);
        } catch (KeyNotRecognised) {
            throw new KeptProviderKeyUnreadable();
        }

        if ($providerKey->kind() !== $kind) {
            throw new KeptProviderKeyUnreadable();
        }

        return $providerKey;
    }

    private function file(): string
    {
        return $this->directory.'/'.self::FILE;
    }
}
