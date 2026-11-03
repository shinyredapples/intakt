<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use SensitiveParameter;

// One redaction for every text that leaves Intakt (posted review text, log records), so a rule added here reaches all of them.
final readonly class Redaction
{
    public const string REDACTED = '[redacted]';

    // Known places and shapes too, because a credential Intakt does not hold (a minted token, a password in a DSN) can still reach a text.
    private const array PATTERNS = [
        '~(?<=://)[^\s/?#@"\'<>]+@~' => self::REDACTED.'@',
        '~\b((?:proxy-)?authorization|x-api-key|api-key)(["\']?\s*[:=]\s*\[?["\']?)((?:basic|bearer|digest|token|bot)\s+)?[^\s"\',;\]]+~i' => '$1$2$3'.self::REDACTED,
        '~-----BEGIN[A-Z ]*PRIVATE KEY-----.*?-----END[A-Z ]*PRIVATE KEY-----~s' => self::REDACTED,
        '~\b(?:gh[opsur]_[A-Za-z0-9]{36,}|github_pat_\w{22,}|sk-[\w-]{20,}|eyJ[\w-]+\.eyJ[\w-]+\.[\w-]+)~' => self::REDACTED,
    ];

    /** @var list<string> */
    private array $credentials;

    public function __construct(#[SensitiveParameter] string ...$credentials)
    {
        $this->credentials = array_values($credentials);
    }

    public function of(string $text): string
    {
        return $text
            |> $this->withoutCredentials(...)
            |> $this->withoutSecretShapes(...);
    }

    private function withoutCredentials(string $text): string
    {
        // strtr replaces the longest value first, so a credential that contains another is redacted whole.
        return strtr($text, array_fill_keys($this->credentials, self::REDACTED));
    }

    private function withoutSecretShapes(string $text): string
    {
        // Redacted whole when the patterns fail, because such a text could still hold a secret.
        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text) ?? self::REDACTED;
    }
}
