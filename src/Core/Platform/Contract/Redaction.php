<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use SensitiveParameter;

// One redaction for every text that leaves Intakt (posted review text, log records), so a rule added here reaches all of them.
final readonly class Redaction
{
    public const string REDACTED = '[redacted]';

    private const string CREDENTIAL_NAMES = '(?:proxy-)?authorization|x-api-key|api-key';

    // Quotes with any escaping and the arrows of var_export and print_r, because a header reaches a text as JSON inside JSON, shell-quoted ('\'') in a logged command line or as a dumped PHP array too.
    private const string HEADER = '\b('.self::CREDENTIAL_NAMES.')((?:[\\\\"\']+|\])?\s*(?:=>|[:=])\s*\[?)';

    private const string SCHEME = '((?:basic|bearer|digest|token|bot)\s+)?';

    // Known places and shapes too, because a credential Intakt does not hold (a minted token, a password in a DSN) can still reach a text.
    private const array PATTERNS = [
        // After escaped slashes too, because a URL inside a JSON text is written as https:\/\/; with a password, up to the last @ before a space, because a raw password can hold an @, a quote, a slash or a #.
        '~:(?:\\\\*/){2}\K(?:[^\s/?#<>@:\\\\]*:[^\s<>]*|[^\s/?#<>:\\\\]+)@~' => self::REDACTED.'@',
        // Up to the closing quote, escaped as the opening one is, or the end of the line, because a cut-off text can lose the closing quote; a quote behind more backslashes than the opening one has is part of the value, because a Digest header inside a JSON text holds its response between escaped quotes.
        '~'.self::HEADER.'([\\\\"\']*["\'])'.self::SCHEME.'(?:(?!\3)(?:\\\\+["\']|[^\r\n]))+(?:\3)?~i' => '$1$2$3$4'.self::REDACTED.'$3',
        // To the end of the line, because a Digest header holds its response in the parameters that follow and a value can hold a comma; the scheme is possessive and a value never starts with a bracket, a quote or the > of an arrow, so a value redacted already stays as it is.
        '~'.self::HEADER.self::SCHEME.'+(?!array\s*\()[^\s"\'\\\\\[,;\]>][^\r\n]*~i' => '$1$2$3'.self::REDACTED,
        // A list value up to the ) indented as its (, or the end of the text, because its credentials are on the lines below the arrow and a value can hold a ).
        '~\b('.self::CREDENTIAL_NAMES.')((?:[\\\\"\']+|\])?\s*=>\h*)(?|\h*\R(\h*)array\h*|Array\h*\R(\h*)|()array\h*)\((?:.*?\R\3\)|.*)~is' => '$1$2'.self::REDACTED,
        '~-----BEGIN[A-Z ]*PRIVATE KEY-----.*?-----END[A-Z ]*PRIVATE KEY-----~s' => self::REDACTED,
        '~\b(?:gh[opsur]_[A-Za-z0-9]{36,}|github_pat_\w{22,}|sk-[\w-]{20,}|eyJ[\w-]+\.eyJ[\w-]+\.[\w-]+)~' => self::REDACTED,
    ];

    private ?string $credentials;

    public function __construct(#[SensitiveParameter] string ...$credentials)
    {
        $this->credentials = $this->patternOf(array_values($credentials));
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

    /**
     * @param list<string> $credentials
     */
    private function patternOf(#[SensitiveParameter] array $credentials): ?string
    {
        $credentials = array_unique(array_filter($credentials, static fn (string $credential): bool => '' !== $credential));
        if ([] === $credentials) {
            return null;
        }
        // Longest first, so a credential that contains another is redacted whole.
        usort($credentials, static fn (string $one, string $other): int => strlen($other) <=> strlen($one));

        return '~'.implode('|', array_map($this->atAnyEscapingDepth(...), $credentials)).'~';
    }

    // Each character JSON escapes may follow any run of backslashes, because a credential inside JSON inside JSON (a logged request body) is escaped once more at each level.
    private function atAnyEscapingDepth(#[SensitiveParameter] string $credential): string
    {
        return implode('', array_map($this->characterAtAnyEscapingDepth(...), mb_str_split($credential, 1, 'UTF-8')));
    }

    private function characterAtAnyEscapingDepth(#[SensitiveParameter] string $character): string
    {
        $escaped = substr(json_encode($character, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1);
        if ($escaped === $character) {
            return preg_quote($character, '~');
        }

        return '(?:'.preg_quote($character, '~').'|\\\\+'.preg_quote(substr($escaped, 1), '~').')';
    }

    private function withoutCredentials(string $text): string
    {
        if (null === $this->credentials) {
            return $text;
        }

        // Redacted whole when the pattern fails, because such a text could still hold a credential.
        return preg_replace($this->credentials, self::REDACTED, $text) ?? self::REDACTED;
    }

    private function withoutSecretShapes(string $text): string
    {
        // Redacted whole when the patterns fail, because such a text could still hold a secret.
        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text) ?? self::REDACTED;
    }
}
