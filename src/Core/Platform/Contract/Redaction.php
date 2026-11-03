<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use SensitiveParameter;

// One redaction for every text that leaves Intakt (posted review text, log records), so a rule added here reaches all of them.
final readonly class Redaction
{
    public const string REDACTED = '[redacted]';

    private const string CREDENTIAL_NAMES = '(?:proxy-)?authorization|x-api-key|api-key';

    private const string HEADER = '\b('.self::CREDENTIAL_NAMES.')(["\']?\s*[:=]\s*\[?)';

    private const string SCHEME = '((?:basic|bearer|digest|token|bot)\s+)?';

    // Known places and shapes too, because a credential Intakt does not hold (a minted token, a password in a DSN) can still reach a text.
    private const array PATTERNS = [
        // Up to the last @ before the host, because a raw password can hold an @ or a quote.
        '~(?<=://)[^\s/?#<>]+@~' => self::REDACTED.'@',
        // Up to the closing quote or the end of the line, because a cut-off text can lose the closing quote.
        '~'.self::HEADER.'(["\'])'.self::SCHEME.'(?:(?!\3)[^\r\n])+\3?~i' => '$1$2$3$4'.self::REDACTED.'$3',
        // With the name="value" parameters that follow, because a Digest header holds its response in them; the scheme is possessive and a value never starts with a bracket, so a value redacted already stays as it is.
        '~'.self::HEADER.self::SCHEME.'+[^\s"\'\[,;\]](?:[^\s"\',;\]]|\s*=\s*|"[^"\r\n]*")*(?:\s*,\s*[\w-]+\s*=\s*(?:"[^"\r\n]*"|[^\s"\',;\]]+))*~i' => '$1$2$3'.self::REDACTED,
        '~-----BEGIN[A-Z ]*PRIVATE KEY-----.*?-----END[A-Z ]*PRIVATE KEY-----~s' => self::REDACTED,
        '~\b(?:gh[opsur]_[A-Za-z0-9]{36,}|github_pat_\w{22,}|sk-[\w-]{20,}|eyJ[\w-]+\.eyJ[\w-]+\.[\w-]+)~' => self::REDACTED,
    ];

    /** @var list<string> */
    private array $credentials;

    public function __construct(#[SensitiveParameter] string ...$credentials)
    {
        $this->credentials = array_values(array_unique(array_merge(...array_map(self::spellings(...), array_values($credentials)))));
    }

    public function of(string $text): string
    {
        return $text
            |> $this->withoutCredentials(...)
            |> $this->withoutSecretShapes(...);
    }

    // Public because a value stored under a name (a header in a map) has no name beside it for a text pattern to see.
    public function namesACredential(string $name): bool
    {
        return 1 === preg_match('~^(?:'.self::CREDENTIAL_NAMES.')$~i', $name);
    }

    // Escaped too, because a credential inside a JSON text (a logged request body) is written with its quotes and slashes escaped.
    /**
     * @return list<string>
     */
    private static function spellings(#[SensitiveParameter] string $credential): array
    {
        return [
            $credential,
            substr(json_encode($credential, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1),
            substr(json_encode($credential, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1),
        ];
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
