<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Throwaway SSH keys made by ssh-keygen in a scratch folder, so no key is ever written into the repository.
final readonly class SigningKeys
{
    public string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/intakt-keys-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    public function privateKeyOf(string $name): string
    {
        $file = $this->directory.'/'.$name;

        if (!is_file($file)) {
            $process = new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $name, '-f', $file]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException($process->getErrorOutput());
            }
        }

        return $file;
    }

    public function publicKeyFileOf(string $name): string
    {
        return $this->privateKeyOf($name).'.pub';
    }

    public function publicKeyOf(string $name): string
    {
        return trim((string) file_get_contents($this->publicKeyFileOf($name)));
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->directory);
    }
}
