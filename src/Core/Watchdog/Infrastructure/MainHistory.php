<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watchdog\Contract\CommitHistory;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

// Signatures are checked with ssh-keygen against the owner's keys alone, because HardenedGit pins git's own signature programs to /dev/null on purpose.
final readonly class MainHistory implements CommitHistory
{
    // The full ref, because a tag or a remote branch named main must not stand in for the branch.
    private const string MAIN = 'refs/heads/main';

    // One limit for every git and ssh-keygen call, because a hung call must not hold the watchdog forever.
    private const float TIMEOUT_SECONDS = 60;

    // Git names the signature header after the repository's object format.
    private const array SIGNATURE_HEADERS = ['sha1' => 'gpgsig', 'sha256' => 'gpgsig-sha256'];

    private const string SSH_ARMOR = '-----BEGIN SSH SIGNATURE-----';
    private const string NAMESPACE = 'git';
    private const string PRINCIPAL = 'owner';
    private const string PROGRAM = 'ssh-keygen';

    // Strict, because a quote, an option or a line break in a key would write another line or option into the allowed-signers file.
    private const string PUBLIC_KEY = '/\A(?<type>(?:sk-)?(?:ssh|ecdsa)-[a-z0-9-]+(?:@openssh\.com)?) (?<key>[A-Za-z0-9+\/]+={0,2})(?: [^\x00-\x1f\x7f"]*)?\z/';

    /** @var list<string> */
    private array $ownerKeys;

    /**
     * @param list<string> $ownerKeys
     *
     * @throws OwnerKeyRefused
     */
    public function __construct(
        private HardenedGit $git,
        private string $repository,
        array $ownerKeys,
        private Filesystem $filesystem,
    ) {
        $this->ownerKeys = array_map($this->strictKey(...), $ownerKeys, array_keys($ownerKeys));
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    public function contains(string $commit): bool
    {
        return $this->holds(['merge-base', '--is-ancestor', $commit, self::MAIN]);
    }

    /**
     * @return list<string>
     *
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    public function firstParentsSince(string $commit): array
    {
        $commits = trim($this->read(['rev-list', '--first-parent', '--reverse', $commit.'..'.self::MAIN]));

        return '' === $commits ? [] : explode("\n", $commits);
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    public function signatureOf(string $commit): SignatureStatus
    {
        $header = self::SIGNATURE_HEADERS[trim($this->read(['rev-parse', '--show-object-format']))] ?? null;
        // A format git adds later has a header this class does not know, so its commits fail closed instead of reading as unsigned.
        if (null === $header) {
            return SignatureStatus::UnknownKey;
        }

        $signed = $this->split($this->read(['cat-file', 'commit', $commit]), $header);
        if (null === $signed) {
            return SignatureStatus::Unsigned;
        }

        return $this->ownerSigned(...$signed) ? SignatureStatus::OwnerSigned : SignatureStatus::UnknownKey;
    }

    private function strictKey(string $key, int $position): string
    {
        if (1 !== preg_match(self::PUBLIC_KEY, $key, $parts)) {
            throw OwnerKeyRefused::notAPublicKey($position + 1);
        }

        // Without the comment, because only the type and the key bytes decide who signed.
        return $parts['type'].' '.$parts['key'];
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function holds(array $arguments): bool
    {
        $answer = $this->run($arguments);

        return match ($answer->getExitCode()) {
            0 => true,
            1 => false,
            // Any other exit is a failed read, never an answer, because "not contained" would report a force push that did not happen.
            default => throw new ProcessFailedException($answer),
        };
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function read(array $arguments): string
    {
        $answer = $this->run($arguments);

        return $answer->isSuccessful() ? $answer->getOutput() : throw new ProcessFailedException($answer);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function run(array $arguments): Process
    {
        // Resolved here, because a relative path would otherwise be read against wherever the process happens to run.
        $directory = realpath($this->repository);
        $call = $this->git->call($arguments, false === $directory ? $this->repository : $directory);
        $process = new Process($call->command, $call->directory, $call->environment, null, self::TIMEOUT_SECONDS);
        $process->run();

        return $process;
    }

    // Read line by line up to the first empty line, because a signature header in the message is not a header.
    /**
     * @return array{payload: string, signature: string}|null
     */
    private function split(string $object, string $header): ?array
    {
        $lines = explode("\n", $object);
        $payload = [];
        $signature = [];
        $inSignature = false;
        foreach ($lines as $index => $line) {
            if ('' === $line) {
                return $this->signedParts([...$payload, ...array_slice($lines, $index)], $signature);
            }
            if (str_starts_with($line, $header.' ')) {
                // A second signature header makes the signature ambiguous, so the commit gets an empty signature that no key verifies.
                if ([] !== $signature) {
                    return ['payload' => '', 'signature' => ''];
                }
                $signature[] = substr($line, strlen($header) + 1);
                $inSignature = true;
                continue;
            }
            if ($inSignature && str_starts_with($line, ' ')) {
                $signature[] = substr($line, 1);
                continue;
            }
            $inSignature = false;
            $payload[] = $line;
        }

        return $this->signedParts($payload, $signature);
    }

    /**
     * @param list<string> $payload
     * @param list<string> $signature
     *
     * @return array{payload: string, signature: string}|null
     */
    private function signedParts(array $payload, array $signature): ?array
    {
        if ([] === $signature) {
            return null;
        }

        return ['payload' => implode("\n", $payload), 'signature' => implode("\n", $signature)."\n"];
    }

    private function ownerSigned(string $payload, string $signature): bool
    {
        if (!str_starts_with($signature, self::SSH_ARMOR."\n") || [] === $this->ownerKeys) {
            return false;
        }

        $signers = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-owner-signers-');
        $signatureFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-owner-signature-');

        try {
            $this->filesystem->dumpFile($signers, $this->allowedSigners());
            $this->filesystem->dumpFile($signatureFile, $signature);

            return $this->verifies($signers, $signatureFile, $payload);
        } finally {
            $this->filesystem->remove([$signers, $signatureFile]);
        }
    }

    private function allowedSigners(): string
    {
        $lines = array_map(static fn (string $key): string => sprintf('%s namespaces="%s" %s'."\n", self::PRINCIPAL, self::NAMESPACE, $key), $this->ownerKeys);

        return implode('', $lines);
    }

    private function verifies(string $signers, string $signatureFile, string $payload): bool
    {
        $check = new Process([self::PROGRAM, '-Y', 'verify', '-f', $signers, '-I', self::PRINCIPAL, '-n', self::NAMESPACE, '-s', $signatureFile], null, ['SSH_ASKPASS' => false, 'SSH_ASKPASS_REQUIRE' => 'never'], $payload, self::TIMEOUT_SECONDS);

        try {
            return 0 === $check->run();
        } catch (RuntimeException) {
            // Fail closed: a check that timed out or did not start proves no owner signed, so the commit is a violation.
            return false;
        }
    }
}
