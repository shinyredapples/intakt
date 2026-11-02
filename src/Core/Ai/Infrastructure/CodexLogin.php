<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

// The login file the Codex CLI writes (auth.json); keys Intakt does not read are kept as they are, so the CLI still finds them.
final readonly class CodexLogin
{
    // Codex's own CLI refreshes a login older than eight days, so refreshing after seven leaves the CLI nothing to write.
    private const string REFRESH_AFTER = '-7 days';

    public const array TOKENS = ['id_token', 'access_token', 'refresh_token'];

    /**
     * @param array<mixed>                    $contents
     * @param array<string, non-empty-string> $tokens
     */
    private function __construct(
        private array $contents,
        private array $tokens,
        private DateTimeImmutable $lastRefresh,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $contents = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CodexLoginUnusable('The Codex login is no JSON.');
        }

        $tokens = is_array($contents) ? $contents['tokens'] ?? null : null;

        if (!is_array($contents) || !is_array($tokens)) {
            throw new CodexLoginUnusable('The Codex login holds no tokens.');
        }

        return new self($contents, self::tokensOf($tokens), self::momentOf($contents['last_refresh'] ?? null));
    }

    public function isDueAt(DateTimeImmutable $now): bool
    {
        return $this->lastRefresh <= $now->modify(self::REFRESH_AFTER);
    }

    public function refreshToken(): string
    {
        return $this->tokens['refresh_token'];
    }

    /**
     * @param array<string, non-empty-string> $tokens
     */
    public function refreshed(array $tokens, DateTimeImmutable $now): self
    {
        $merged = [...$this->tokens, ...$tokens];
        $contents = [...$this->contents, 'tokens' => [...(array) $this->contents['tokens'], ...$merged], 'last_refresh' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];

        return new self($contents, $merged, $now);
    }

    public function json(): string
    {
        return json_encode($this->contents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<mixed> $tokens
     *
     * @return array<string, non-empty-string>
     */
    private static function tokensOf(array $tokens): array
    {
        $kept = [];

        foreach (self::TOKENS as $name) {
            $token = $tokens[$name] ?? null;

            if (!is_string($token) || '' === $token) {
                throw new CodexLoginUnusable('The Codex login has no '.$name.'.');
            }

            $kept[$name] = $token;
        }

        return $kept;
    }

    // Codex writes RFC 3339 with up to nanoseconds, more than PHP parses, and the refresh needs whole seconds only.
    private static function momentOf(mixed $lastRefresh): DateTimeImmutable
    {
        $moment = is_string($lastRefresh)
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', (string) preg_replace('/\.\d+(?=Z|[+-]\d{2}:\d{2}$)/', '', $lastRefresh), new DateTimeZone('UTC'))
            : false;

        if (false === $moment) {
            throw new CodexLoginUnusable('The Codex login has no readable last_refresh.');
        }

        return $moment;
    }
}
