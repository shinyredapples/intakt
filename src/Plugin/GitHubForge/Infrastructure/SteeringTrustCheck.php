<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\SteeringTrust;
use NoDiscard;

// Asked on each event and never cached, because write access can be taken away between two events: docs/design/05-architecture/14-threat-model.md
final readonly class SteeringTrustCheck
{
    private const string LOGIN = '/^[A-Za-z0-9][A-Za-z0-9-]{0,38}(?:\[bot\])?$/';
    private const string REPOSITORY = '#^[A-Za-z0-9][A-Za-z0-9-]*/(?!\.+$)[A-Za-z0-9._-]+$#';
    // GitHub reports the maintain role as write and the triage role as read, so triage never steers.
    private const array LEVELS = ['admin' => true, 'write' => true, 'read' => false, 'none' => false];

    public function __construct(
        private SteeringIdentities $identities,
        private TrustAnswers $github,
        private CheckedInstallationTokens $tokens,
    ) {
    }

    /**
     * @throws TrustNotChecked
     */
    #[NoDiscard('a trust decision nobody looks at lets anyone steer')]
    public function check(string $repository, string $login): SteeringTrust
    {
        $this->refuseMalformed($repository, $login);

        if (!$this->identities->isBot($login)) {
            return $this->trustOfPerson($repository, $login);
        }

        $identity = $this->identityOfBot($login);
        if (SteeringTrust::ForeignBot === $identity) {
            return $identity;
        }

        return $this->trustOfIdentity($repository, $login, $identity);
    }

    private function refuseMalformed(string $repository, string $login): void
    {
        if (1 !== preg_match(self::REPOSITORY, $repository)) {
            throw TrustNotChecked::becauseTheRepositoryIsMalformed();
        }
        if (1 !== preg_match(self::LOGIN, $login)) {
            throw TrustNotChecked::becauseTheAccountIsMalformed();
        }
    }

    private function trustOfPerson(string $repository, string $login): SteeringTrust
    {
        $permission = $this->permissionOf($repository, $login);

        return match (true) {
            $permission['bot'] => SteeringTrust::ForeignBot,
            $permission['writes'] => SteeringTrust::Writer,
            default => SteeringTrust::NonWriter,
        };
    }

    private function identityOfBot(string $login): SteeringTrust
    {
        return match (true) {
            $this->identities->isMaintainer($login) => SteeringTrust::Maintainer,
            $this->identities->isOwn($login) => SteeringTrust::OwnIdentity,
            default => SteeringTrust::ForeignBot,
        };
    }

    // A trusted name is not enough, because an App can lose its write access to one repository while it keeps its name.
    private function trustOfIdentity(string $repository, string $login, SteeringTrust $identity): SteeringTrust
    {
        $permission = $this->permissionOf($repository, $login);

        return $permission['bot'] && $permission['writes'] ? $identity : SteeringTrust::NonWriter;
    }

    /**
     * @return array{writes: bool, bot: bool}
     */
    private function permissionOf(string $repository, string $login): array
    {
        [$owner, $name] = explode('/', $repository, 2);
        $path = sprintf('/repos/%s/%s/collaborators/%s/permission', rawurlencode($owner), rawurlencode($name), rawurlencode($login));

        return $this->permissionFrom($this->github->ofInstallation($path, $this->installationToken()), $login);
    }

    // No other credential is tried, because a fallback would let a lost App identity still decide who steers.
    private function installationToken(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $failure) {
            throw TrustNotChecked::becauseNoCredential($failure->getMessage());
        }
    }

    /**
     * @param array<mixed> $answer
     *
     * @return array{writes: bool, bot: bool}
     */
    private function permissionFrom(array $answer, string $login): array
    {
        $level = $answer['permission'] ?? null;
        $user = $answer['user'] ?? null;
        $answeredLogin = is_array($user) ? ($user['login'] ?? null) : null;
        $type = is_array($user) ? ($user['type'] ?? null) : null;
        if (!is_string($level) || !isset(self::LEVELS[$level]) || !is_string($answeredLogin) || !is_string($type) || strtolower($answeredLogin) !== strtolower($login)) {
            throw TrustNotChecked::becauseTheAnswerIsInvalid();
        }

        return ['writes' => self::LEVELS[$level], 'bot' => 'Bot' === $type];
    }
}
