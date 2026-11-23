<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

// Signatures are checked with ssh-keygen against the owner's and the peer loop's keys alone, because HardenedGit pins git's own signature programs to /dev/null on purpose.
final readonly class TrustedSignatures
{
    private const string SSH_ARMOR = '-----BEGIN SSH SIGNATURE-----';
    private const string NAMESPACE = 'git';
    private const string OWNER = 'owner';
    private const string PEER = 'peer';

    // One principal per signer class, asked owner first, because a peer key must never pass as the owner's (nor the reverse), and a key given as both keeps the owner's notice.
    private const array SIGNED_AS = [self::OWNER => SignatureStatus::OwnerSigned, self::PEER => SignatureStatus::PeerSigned];

    private const string PROGRAM = 'ssh-keygen';
    private const float TIMEOUT_SECONDS = 60;

    /** @var array<string, list<string>> */
    private array $keys;

    /**
     * @param list<string> $ownerKeys
     * @param list<string> $peerKeys
     *
     * @throws OwnerKeyRefused
     */
    public function __construct(
        array $ownerKeys,
        array $peerKeys,
        private Filesystem $filesystem,
    ) {
        $this->keys = [
            self::OWNER => array_map(static fn (string $line, int $index): string => OwnerKey::fromLine($line, self::OWNER, $index + 1)->typeAndKey, $ownerKeys, array_keys($ownerKeys)),
            self::PEER => array_map(static fn (string $line, int $index): string => OwnerKey::fromLine($line, self::PEER, $index + 1)->typeAndKey, $peerKeys, array_keys($peerKeys)),
        ];
    }

    /**
     * @throws IOException
     */
    public function signerOf(string $payload, string $signature): SignatureStatus
    {
        if (!str_starts_with($signature, self::SSH_ARMOR."\n") || [] === array_merge(...array_values($this->keys))) {
            return SignatureStatus::UnknownKey;
        }

        $files = [];

        try {
            // Allocated inside the try, because a failure on the second file must still remove the first.
            $files[] = $signers = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-trusted-signers-');
            $files[] = $signatureFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-trusted-signature-');
            $this->filesystem->dumpFile($signers, $this->allowedSigners());
            $this->filesystem->dumpFile($signatureFile, $signature);

            return $this->verifiedSigner($signers, $signatureFile, $payload);
        } catch (IOException) {
            // Fail closed: a check that could not be prepared proves no trusted key signed, so the commit is an unknown key.
            return SignatureStatus::UnknownKey;
        } finally {
            $this->filesystem->remove($files);
        }
    }

    private function allowedSigners(): string
    {
        $lines = [];
        foreach ($this->keys as $principal => $keys) {
            foreach ($keys as $key) {
                $lines[] = sprintf('%s namespaces="%s" %s'."\n", $principal, self::NAMESPACE, $key);
            }
        }

        return implode('', $lines);
    }

    private function verifiedSigner(string $signers, string $signatureFile, string $payload): SignatureStatus
    {
        try {
            foreach (self::SIGNED_AS as $principal => $status) {
                $command = [self::PROGRAM, '-Y', 'verify', '-f', $signers, '-I', $principal, '-n', self::NAMESPACE, '-s', $signatureFile];
                if ([] !== $this->keys[$principal] && $this->verifies($command, $payload)) {
                    return $status;
                }
            }
        } catch (RuntimeException) {
            // Fail closed for every class: an owner check that timed out, was killed or did not start says nothing about the owner's key, so a later peer match must not hide the owner's notice.
            return SignatureStatus::UnknownKey;
        }

        return SignatureStatus::UnknownKey;
    }

    /**
     * @param list<string> $command
     *
     * @throws RuntimeException
     */
    private function verifies(array $command, string $payload): bool
    {
        return 0 === new Process($command, null, ['SSH_ASKPASS' => false, 'SSH_ASKPASS_REQUIRE' => 'never'], $payload, self::TIMEOUT_SECONDS)->run();
    }
}
