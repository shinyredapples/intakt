<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use OpenSSLAsymmetricKey;
use RuntimeException;

// Generated per test run, so no key is ever committed.
final readonly class AppKey
{
    private function __construct(
        public string $private,
        public OpenSSLAsymmetricKey $public,
    ) {
    }

    public static function generate(): self
    {
        $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $private = null;
        $details = false === $pair ? false : openssl_pkey_get_details($pair);
        if (false === $pair || false === $details || !openssl_pkey_export($pair, $private) || !is_string($private) || !is_string($details['key'])) {
            throw new RuntimeException('OpenSSL cannot generate a key.');
        }
        $public = openssl_pkey_get_public($details['key']);
        if (false === $public) {
            throw new RuntimeException('OpenSSL cannot read the public key.');
        }

        return new self($private, $public);
    }

    // Elliptic curve, because OpenSSL signs with such a key while GitHub refuses its signature under RS256.
    public static function ellipticCurvePem(): string
    {
        $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $private = null;
        if (false === $pair || !openssl_pkey_export($pair, $private) || !is_string($private)) {
            throw new RuntimeException('OpenSSL cannot generate a key.');
        }

        return $private;
    }

    public function body(): string
    {
        return explode('-----', $this->private)[2];
    }
}
