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

    public function __construct(
        #[Autowire(param: GitServices::SIGNING_KEY)]
        private string $file,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws BranchNotPushed
     */
    public function sign(string $payload): string
    {
        return $this->run(['-Y', 'sign', '-f', $this->file, '-n', self::NAMESPACE], $payload, 'the signing key cannot sign');
    }

    // Against an allowed-signers file of this key alone, so a commit signed by any other key, valid or not, does not verify.
    /**
     * @throws BranchNotPushed
     */
    public function signed(string $payload, string $signature): bool
    {
        $key = explode(' ', trim($this->run(['-y', '-f', $this->file], null, 'the signing key cannot be read')));
        $allowed = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-signers-');
        $signatureFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-signature-');

        try {
            $this->filesystem->dumpFile($allowed, sprintf('%s namespaces="%s" %s %s'."\n", self::PRINCIPAL, self::NAMESPACE, $key[0], $key[1] ?? ''));
            $this->filesystem->dumpFile($signatureFile, $signature);
            $check = new Process(['ssh-keygen', '-Y', 'verify', '-f', $allowed, '-I', self::PRINCIPAL, '-n', self::NAMESPACE, '-s', $signatureFile], null, null, $payload, 60);

            return 0 === $check->run();
        } finally {
            $this->filesystem->remove([$allowed, $signatureFile]);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @throws BranchNotPushed
     */
    private function run(array $arguments, ?string $input, string $failure): string
    {
        $process = new Process(['ssh-keygen', ...$arguments], null, ['SSH_ASKPASS' => false, 'SSH_ASKPASS_REQUIRE' => 'never'], $input, 60);
        $process->run();

        // Not the error output, because it can name the key's path.
        return $process->isSuccessful() ? $process->getOutput() : throw BranchNotPushed::because($failure);
    }
}
