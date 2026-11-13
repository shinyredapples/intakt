<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use InvalidArgumentException;

final readonly class OwnerKeys
{
    // Type and base64 body only: a comment or an option field in a key line would become part of the allowed-signers line git reads.
    private const string PUBLIC_KEY = '/\A([a-z0-9@.-]+) ([A-Za-z0-9+\/]+={0,3})(?: [^\n\r]*)?\z/';

    /**
     * @param list<string> $keys type and body of each registered public key
     */
    private function __construct(
        private array $keys,
    ) {
    }

    /**
     * @param list<string> $publicKeys public keys as OpenSSH writes them (`<type> <body> [comment]`)
     *
     * @throws InvalidArgumentException
     */
    public static function of(array $publicKeys): self
    {
        $keys = [];
        foreach ($publicKeys as $line) {
            if (1 !== preg_match(self::PUBLIC_KEY, trim($line), $parts)) {
                throw new InvalidArgumentException('an owner key must be an OpenSSH public key line');
            }

            $keys[] = $parts[1].' '.$parts[2];
        }

        return new self($keys);
    }

    // The format ssh-keygen reads for its allowed signers; one principal, limited to git signatures.
    public function allowedSigners(): string
    {
        $lines = array_map(static fn (string $key): string => 'owner namespaces="git" '.$key."\n", $this->keys);

        return implode('', $lines);
    }
}
