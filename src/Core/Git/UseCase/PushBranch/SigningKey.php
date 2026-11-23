<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\GitServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// ssh-keygen signs and checks, so the key never reaches git's own config and the commit's signature is what git itself writes for an SSH key.
final readonly class SigningKey
{
    public const string NAMESPACE = 'git';
    public const string PRINCIPAL = 'orchestrator';
    private const string ED25519 = 'ssh-ed25519';
    private const string PROGRAM = 'ssh-keygen';

    public function __construct(
        #[Autowire(param: GitServices::SIGNING_KEY)]
        private string $file,
        private Filesystem $filesystem,
        private GuardedRun $guardedRun,
        private float $timeoutSeconds = 60,
    ) {
    }

    /**
     * @throws BranchNotPushed
     */
    public function sign(string $payload): string
    {
        // Only ed25519, because a retry after a crash must produce the same commit ids, and an ECDSA signature takes a fresh random nonce each time.
        if (self::ED25519 !== $this->publicKey()[0]) {
            throw BranchNotPushed::because('the signing key is not an ed25519 key, so a retry would sign the same commits differently');
        }

        return $this->run(['-Y', 'sign', '-f', $this->file, '-n', self::NAMESPACE], $payload, 'the signing key cannot sign');
    }

    // Against an allowed-signers file of this key alone, so a commit signed by any other key, valid or not, does not verify.
    /**
     * @throws BranchNotPushed
     */
    public function signed(string $payload, string $signature): bool
    {
        $key = $this->publicKey();
        $allowed = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-signers-');
        $signatureFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-signature-');

        try {
            $this->filesystem->dumpFile($allowed, sprintf('%s namespaces="%s" %s %s'."\n", self::PRINCIPAL, self::NAMESPACE, $key[0], $key[1] ?? ''));
            $this->filesystem->dumpFile($signatureFile, $signature);
            $check = new Process([self::PROGRAM, '-Y', 'verify', '-f', $allowed, '-I', self::PRINCIPAL, '-n', self::NAMESPACE, '-s', $signatureFile], null, null, $payload, $this->timeoutSeconds);

            // A check that was stopped proves nothing about the signature, so it stops the push instead of answering false.
            return $this->guardedRun->run($check, self::PROGRAM)->isSuccessful();
        } finally {
            $this->filesystem->remove([$allowed, $signatureFile]);
        }
    }

    /**
     * @return non-empty-list<string>
     *
     * @throws BranchNotPushed
     */
    private function publicKey(): array
    {
        return explode(' ', trim($this->run(['-y', '-f', $this->file], null, 'the signing key cannot be read')));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws BranchNotPushed
     */
    private function run(array $arguments, ?string $input, string $failure): string
    {
        $process = $this->guardedRun->run(new Process([self::PROGRAM, ...$arguments], null, ['SSH_ASKPASS' => false, 'SSH_ASKPASS_REQUIRE' => 'never'], $input, $this->timeoutSeconds), self::PROGRAM);

        // Not the error output, because it can name the key's path.
        return $process->isSuccessful() ? $process->getOutput() : throw BranchNotPushed::because($failure);
    }
}
