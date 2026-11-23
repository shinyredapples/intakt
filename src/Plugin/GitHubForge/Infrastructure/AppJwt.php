<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use NoDiscard;
use SensitiveParameter;
use Symfony\Component\Clock\ClockInterface;

// Short-lived on purpose, because it only exists to be exchanged for an installation token.
final readonly class AppJwt
{
    // GitHub refuses a lifetime over ten minutes because it wants short-lived proofs; the start is set back because clocks drift.
    private const int LIFETIME_SECONDS = 540;
    private const int DRIFT_SECONDS = 60;

    public function __construct(
        private string $appId,
        #[SensitiveParameter]
        private string $privateKey,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InstallationTokenNotMinted
     */
    #[NoDiscard('the signed token is the only proof of the App identity')]
    public function sign(): string
    {
        $now = $this->clock->now()->getTimestamp();
        $payload = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$this->encode([
            'iat' => $now - self::DRIFT_SECONDS,
            'exp' => $now + self::LIFETIME_SECONDS,
            'iss' => $this->appId,
        ]);

        return $payload.'.'.$this->base64Url($this->signature($payload));
    }

    /**
     * @param array<string, int|string> $segment
     */
    private function encode(array $segment): string
    {
        return $this->base64Url(json_encode($segment, JSON_THROW_ON_ERROR));
    }

    private function signature(string $payload): string
    {
        $key = openssl_pkey_get_private($this->privateKey);
        $signature = null;
        if (false === $key || !openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256) || !is_string($signature)) {
            throw InstallationTokenNotMinted::becauseTheKeyCannotSign();
        }

        return $signature;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
