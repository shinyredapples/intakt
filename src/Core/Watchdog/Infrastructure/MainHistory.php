<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watchdog\Contract\CommitHistory;
use Intakt\Core\Watchdog\Contract\MainHistoryRewritten;
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

    // One header per object format, because git names the signature header after the hash the repository uses.
    private const array SIGNATURE_HEADERS = ['sha1' => 'gpgsig', 'sha256' => 'gpgsig-sha256'];

    private const string SSH_ARMOR = '-----BEGIN SSH SIGNATURE-----';
    private const string NAMESPACE = 'git';
    private const string PRINCIPAL = 'owner';
    private const string PROGRAM = 'ssh-keygen';

    // Strict, because a quote, an option or a line break in a key would write another line or option into the allowed-signers file.
    private const string PUBLIC_KEY = '/\A(?<type>(?:sk-)?(?:ssh|ecdsa)-[a-z0-9-]+(?:@openssh\.com)?) (?<key>[A-Za-z0-9+\/]+={0,2})(?: [^\x00-\x1f\x7f"]*)?\z/';

    // Listed per type (an exact length, an exact value, or null for any field that is not empty), because the fields after the type decide whether the bytes are a whole key.
    private const array KEY_FIELDS = [
        'ssh-ed25519' => [32],
        'ssh-rsa' => [null, null],
        'ecdsa-sha2-nistp256' => ['nistp256', 65],
        'ecdsa-sha2-nistp384' => ['nistp384', 97],
        'ecdsa-sha2-nistp521' => ['nistp521', 133],
    ];

    // Derived from its plain type, because a key on a security key carries the same fields and then its application.
    private const string SECURITY_KEY = '/\Ask-(?<plain>ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com\z/';

    // The DER head of a public key on each curve (hex), because only OpenSSL's decode tells a point on the curve from a point off it.
    private const array CURVE_KEY_HEADS = [
        'ecdsa-sha2-nistp256' => '3059301306072a8648ce3d020106082a8648ce3d030107034200',
        'ecdsa-sha2-nistp384' => '3076301006072a8648ce3d020106052b81040022036200',
        'ecdsa-sha2-nistp521' => '30819b301006072a8648ce3d020106052b8104002303818600',
    ];

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
        return $this->holds(['merge-base', '--is-ancestor', $commit, $this->tip()]);
    }

    /**
     * @return list<string>
     *
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     * @throws MainHistoryRewritten
     */
    public function firstParentsSince(string $commit): array
    {
        // One pinned tip for the ancestry check and the walk, because a force push between two reads of the ref would walk a history that dropped the commit.
        $tip = $this->tip();
        if (!$this->holds(['merge-base', '--is-ancestor', $commit, $tip])) {
            throw MainHistoryRewritten::dropped($commit, $tip);
        }

        $commits = trim($this->read(['rev-list', '--first-parent', '--reverse', $commit.'..'.$tip]));

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
        if (1 !== preg_match(self::PUBLIC_KEY, $key, $parts) || !$this->isAWholeKey((string) base64_decode($parts['key'], true), $parts['type'])) {
            throw OwnerKeyRefused::notAPublicKey($position + 1);
        }

        // Without the comment, because only the type and the key bytes decide who signed.
        return $parts['type'].' '.$parts['key'];
    }

    // The whole key, because a key cut short or with bytes left over would be accepted here and then fail every check later.
    private function isAWholeKey(string $blob, string $type): bool
    {
        $fields = $this->fields($blob);
        $plain = 1 === preg_match(self::SECURITY_KEY, $type, $securityKey) ? $securityKey['plain'] : $type;
        $expected = self::KEY_FIELDS[$plain] ?? null;
        $expected = null !== $expected && $plain !== $type ? [...$expected, null] : $expected;
        if (null === $fields || null === $expected || $type !== array_shift($fields) || count($fields) !== count($expected)) {
            return false;
        }

        return array_all($expected, static fn (int|string|null $rule, int $index): bool => match (true) {
            is_int($rule) => strlen($fields[$index]) === $rule,
            is_string($rule) => $fields[$index] === $rule,
            default => '' !== $fields[$index],
        }) && $this->isOnItsCurve($plain, $fields);
    }

    /**
     * @param list<string> $fields
     */
    private function isOnItsCurve(string $plain, array $fields): bool
    {
        $head = self::CURVE_KEY_HEADS[$plain] ?? null;
        if (null === $head) {
            return true;
        }

        // A point of the right length may still lie off the curve, and ssh-keygen would then refuse every signature the owner makes.
        $der = hex2bin($head).$fields[1];

        return false !== openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n");
    }

    /**
     * @return list<string>|null
     */
    private function fields(string $blob): ?array
    {
        $fields = [];
        $offset = 0;
        while ($offset < strlen($blob)) {
            $length = strlen($blob) - $offset < 4 ? null : unpack('N', $blob, $offset);
            // No key at all, because a length that does not fit what is left must never read as a shorter key.
            if (!is_array($length) || !is_int($length[1]) || $length[1] > strlen($blob) - $offset - 4) {
                return null;
            }
            $fields[] = substr($blob, $offset + 4, $length[1]);
            $offset += 4 + $length[1];
        }

        return $fields;
    }

    /**
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function tip(): string
    {
        // show-ref --verify takes the exact ref only, because rev-parse would fall back to a tag named refs/heads/main once the branch is gone.
        return trim($this->read(['show-ref', '--verify', '--hash', self::MAIN]));
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

    // Only the lines up to the first empty line, because a signature header in the message is not a header.
    /**
     * @return array{payload: string, signature: string}|null
     */
    private function split(string $object, string $header): ?array
    {
        $end = strpos($object, "\n\n");
        $headers = explode("\n", false === $end ? $object : substr($object, 0, $end));
        $message = false === $end ? '' : substr($object, $end);
        $signatureLines = $this->signatureLines($headers, $header);
        // A second signature header makes the signature ambiguous, so the commit gets an empty signature that no key verifies.
        if (null === $signatureLines) {
            return ['payload' => '', 'signature' => ''];
        }
        if ([] === $signatureLines) {
            return null;
        }

        $signature = array_map(static fn (int $index): string => substr($headers[$index], 1), $signatureLines);
        $signature[0] = substr($headers[$signatureLines[0]], strlen($header) + 1);

        return ['payload' => implode("\n", array_diff_key($headers, array_flip($signatureLines))).$message, 'signature' => implode("\n", $signature)."\n"];
    }

    /**
     * @param list<string> $headers
     *
     * @return list<int>|null
     */
    private function signatureLines(array $headers, string $header): ?array
    {
        $lines = [];
        foreach ($headers as $index => $line) {
            $starts = str_starts_with($line, $header.' ');
            if ($starts && [] !== $lines) {
                return null;
            }
            if ($starts || ([] !== $lines && array_last($lines) === $index - 1 && str_starts_with($line, ' '))) {
                $lines[] = $index;
            }
        }

        return $lines;
    }

    private function ownerSigned(string $payload, string $signature): bool
    {
        if (!str_starts_with($signature, self::SSH_ARMOR."\n") || [] === $this->ownerKeys) {
            return false;
        }

        $files = [$signers = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-owner-signers-')];

        try {
            // Allocated inside the try, because a failure here must still remove the signers file.
            $files[] = $signatureFile = $this->filesystem->tempnam(sys_get_temp_dir(), 'intakt-owner-signature-');
            $this->filesystem->dumpFile($signers, $this->allowedSigners());
            $this->filesystem->dumpFile($signatureFile, $signature);

            return $this->verifies($signers, $signatureFile, $payload);
        } finally {
            $this->filesystem->remove($files);
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
