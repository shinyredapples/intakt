<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use InvalidArgumentException;

// The two bot accounts that may steer; every other bot never does, because a CI token can hold write access.
final readonly class SteeringIdentities
{
    private const string BOT_SUFFIX = '[bot]';
    private const string SLUG = '/^[A-Za-z0-9][A-Za-z0-9-]*$/';

    public function __construct(
        private TrustAnswers $github,
        private AppJwt $jwt,
        private string $maintainerAppSlug,
    ) {
        if ('' !== $maintainerAppSlug && 1 !== preg_match(self::SLUG, $maintainerAppSlug)) {
            throw new InvalidArgumentException('GITHUB_MAINTAINER_APP_SLUG must be a GitHub App slug, such as acme-maintainer.');
        }
    }

    public function isBot(string $login): bool
    {
        return str_ends_with($login, self::BOT_SUFFIX);
    }

    public function isMaintainer(string $login): bool
    {
        return '' !== $this->maintainerAppSlug && $this->isLoginOf($this->maintainerAppSlug, $login);
    }

    /**
     * @throws TrustNotChecked
     */
    public function isOwn(string $login): bool
    {
        return $this->isLoginOf($this->ownSlug(), $login);
    }

    // Read from GitHub with the App's own proof, so no setting can name a foreign bot as Intakt.
    private function ownSlug(): string
    {
        try {
            $jwt = $this->jwt->sign();
        } catch (InstallationTokenNotMinted $failure) {
            throw TrustNotChecked::becauseNoCredential($failure->getMessage());
        }

        $slug = $this->github->ofApp('/app', $jwt)['slug'] ?? null;

        return is_string($slug) && 1 === preg_match(self::SLUG, $slug) ? $slug : throw TrustNotChecked::becauseTheAnswerIsInvalid();
    }

    // GitHub logins ignore case, so a bot cannot pass as another by changing it.
    private function isLoginOf(string $slug, string $login): bool
    {
        return strtolower($slug.self::BOT_SUFFIX) === strtolower($login);
    }
}
