<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use SensitiveParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

// The endpoint and public client id the Codex CLI refreshes its own login with, so the orchestrator refreshes it the same way and the CLI never has to.
final readonly class CodexTokenRefresh
{
    private const string ENDPOINT = 'https://auth.openai.com/oauth/token';
    private const string CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    public function __construct(
        private HttpClientInterface $http,
        private ClockInterface $clock,
    ) {
    }

    public function refreshedIfDue(CodexLogin $login): ?CodexLogin
    {
        $now = $this->clock->now();

        if (!$login->isDueAt($now)) {
            return null;
        }

        return $login->refreshed($this->tokensFor($login->refreshToken()), $now);
    }

    /**
     * @return array<string, non-empty-string>
     */
    private function tokensFor(#[SensitiveParameter] string $refreshToken): array
    {
        try {
            $answer = $this->http->request('POST', self::ENDPOINT, ['json' => [
                'client_id' => self::CLIENT_ID,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => 'openid profile email',
            ]])->toArray();
        } catch (ExceptionInterface $failure) {
            throw $this->refusal($failure);
        }

        return $this->validated($answer);
    }

    // A 401 refuses the refresh token itself, which only a new login mends, so it is the CLI's own lost login and takes the account out; anything else may pass.
    private function refusal(ExceptionInterface $failure): Throwable
    {
        if ($failure instanceof HttpExceptionInterface && 401 === $failure->getResponse()->getStatusCode()) {
            return CodexAccountFailure::LoginLost->exception();
        }

        return new CodexLoginUnusable('The Codex login could not be refreshed: '.$failure->getMessage(), $failure->getCode(), previous: $failure);
    }

    // The access token must come back; the others are replaced only when the answer carries them, as the CLI does.
    /**
     * @param array<mixed> $answer
     *
     * @return array<string, non-empty-string>
     */
    private function validated(array $answer): array
    {
        $tokens = [];

        foreach (array_intersect_key($answer, array_flip(CodexLogin::TOKENS)) as $name => $token) {
            if (!is_string($token) || '' === $token) {
                throw new CodexLoginUnusable('The Codex login refresh answered with an unusable '.$name.'.');
            }

            $tokens[$name] = $token;
        }

        if (!array_key_exists('access_token', $tokens)) {
            throw new CodexLoginUnusable('The Codex login refresh answered without an access_token.');
        }

        return $tokens;
    }
}
