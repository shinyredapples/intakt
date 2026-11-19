<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// ssh-keygen checks the signature, because the hardened git call switches git's own signature program off.
final readonly class CommitSignature
{
    private const string PROGRAM = 'ssh-keygen';
    private const string NAMESPACE = 'git';
    private const string PRINCIPAL = 'owner';
    private const string SHA1_HEADER = 'gpgsig';
    private const string SHA256_HEADER = 'gpgsig-sha256';
    private const array SIGNATURE_HEADERS = [self::SHA1_HEADER, self::SHA256_HEADER];
    private const int SHA256_NAME_LENGTH = 64;
    private const int REFUSED = 255;
    private const float TIMEOUT_SECONDS = 60;

    public function __construct(
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<string> $ownerKeys
     *
     * @throws MainNotRead
     */
    public function of(string $commit, string $commitText, array $ownerKeys): SignatureStatus
    {
        [$signature, $payload] = $this->split($commitText, $this->headerFor($commit));

        if (null === $signature) {
            return SignatureStatus::Unsigned;
        }

        return $this->signedBy($ownerKeys, $signature, $payload) ? SignatureStatus::Good : SignatureStatus::UnknownKey;
    }

    // Because git signs a commit of a SHA-256 repository under its own header and keeps the plain one for the SHA-1 form.
    private function headerFor(string $commit): string
    {
        return self::SHA256_NAME_LENGTH === strlen($commit) ? self::SHA256_HEADER : self::SHA1_HEADER;
    }

    /**
     * @return array{?string, string}
     */
    private function split(string $commitText, string $header): array
    {
        [$headers, $message] = array_pad(explode("\n\n", $commitText, 2), 2, '');
        $signature = null;
        $kept = [];

        foreach ($this->entries($headers) as [$name, $lines]) {
            // Because git leaves every signature header out of the text it signs, whichever one it reads.
            if (!in_array($name, self::SIGNATURE_HEADERS, true)) {
                $kept[] = implode("\n", $lines);
            } elseif ($name === $header) {
                $signature ??= $this->unfolded($name, $lines);
            }
        }

        return [$signature, implode("\n", $kept)."\n\n".$message];
    }

    /**
     * @return list<array{string, non-empty-list<string>}>
     */
    private function entries(string $headers): array
    {
        $entries = [];

        // Line by line, because a pattern over the whole block runs out of stack on a long folded header and loses the signature.
        foreach (explode("\n", $headers) as $line) {
            $last = array_key_last($entries);

            // Because git folds a multi-line header value into continuation lines that start with a space.
            if (null !== $last && str_starts_with($line, ' ')) {
                $entries[$last][1][] = $line;
            } else {
                $entries[] = [explode(' ', $line, 2)[0], [$line]];
            }
        }

        return $entries;
    }

    /**
     * @param non-empty-list<string> $lines
     */
    private function unfolded(string $name, array $lines): string
    {
        $value = [substr($lines[0], strlen($name) + 1)];

        foreach (array_slice($lines, 1) as $continuation) {
            $value[] = substr($continuation, 1);
        }

        return implode("\n", $value)."\n";
    }

    /**
     * @param list<string> $ownerKeys
     *
     * @throws MainNotRead
     */
    private function signedBy(array $ownerKeys, string $signature, string $payload): bool
    {
        $allowed = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-owner-keys-');
        $signatureFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-signature-');

        try {
            $this->filesystem->dumpFile($allowed, $this->allowedSigners($ownerKeys));
            $this->filesystem->dumpFile($signatureFile, $signature);
            $exit = $this->verify($allowed, $signatureFile, $payload);
        } finally {
            $this->filesystem->remove([$allowed, $signatureFile]);
        }

        // Only a refusal says the key is not the owner's; any other exit (not found, killed) proves nothing and must not read as one.
        return match ($exit) {
            0 => true,
            self::REFUSED => false,
            default => throw MainNotRead::because(self::PROGRAM.' could not check the signature'),
        };
    }

    /**
     * @throws MainNotRead
     */
    private function verify(string $allowed, string $signatureFile, string $payload): ?int
    {
        $check = new Process([self::PROGRAM, '-Y', 'verify', '-f', $allowed, '-I', self::PRINCIPAL, '-n', self::NAMESPACE, '-s', $signatureFile], null, ['SSH_ASKPASS' => false, 'SSH_ASKPASS_REQUIRE' => 'never'], $payload, self::TIMEOUT_SECONDS);

        try {
            $check->run();
        } catch (ExceptionInterface) {
            throw MainNotRead::because(self::PROGRAM.' did not finish');
        }

        return $check->getExitCode();
    }

    /**
     * @param list<string> $ownerKeys
     */
    private function allowedSigners(array $ownerKeys): string
    {
        $lines = '';
        foreach ($ownerKeys as $key) {
            $fields = explode(' ', $key);
            $lines .= sprintf('%s namespaces="%s" %s %s'."\n", self::PRINCIPAL, self::NAMESPACE, $fields[0], $fields[1]);
        }

        return $lines;
    }
}
