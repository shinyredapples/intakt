<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use OpenSSLAsymmetricKey;

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
        openssl_pkey_export($pair, $private);
        $public = openssl_pkey_get_public(openssl_pkey_get_details($pair)['key']);

        return new self($private, $public);
    }

    public function body(): string
    {
        return explode('-----', $this->private)[2];
    }
}
