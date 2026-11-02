<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AccountError;
use Intakt\Core\Ai\Contract\AccountUnusable;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        } catch (ClientExceptionInterface $refused) {
            throw $this->lostOrUnusable($refused);
        } catch (ExceptionInterface $failure) {
            throw new CodexLoginUnusable('The Codex login could not be refreshed: '.$failure->getMessage(), $failure->getCode(), previous: $failure);
        }

        return $this->validated($answer);
    }

    // The answers the CLI takes for a login only a new sign-in brings back: an unauthorized refresh, or a bad request whose code says the refresh token is no longer granted.
    private function lostOrUnusable(ClientExceptionInterface $refused): RuntimeException
    {
        $answer = $refused->getResponse();
        $body = json_decode($answer->getContent(false), true);
        $error = is_array($body) ? $body['error'] ?? null : null;
        $code = is_array($error) ? $error['code'] ?? null : $error;

        if (401 === $answer->getStatusCode() || (400 === $answer->getStatusCode() && 'invalid_grant' === $code)) {
            return new AccountUnusable(AccountError::LoginLost, $refused);
        }

        return new CodexLoginUnusable('The Codex login could not be refreshed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
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
