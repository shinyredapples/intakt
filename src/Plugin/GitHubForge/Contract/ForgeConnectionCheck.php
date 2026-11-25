<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Read-only on purpose (decision D3 of #3826): it reads the installation with its own scratch rate-limit store and its own token, so it leaves the live readings and pauses untouched.
final readonly class ForgeConnectionCheck
{
    // Short, because a check that waits on GitHub would hang the owner's terminal (decision D5 of #3826).
    private const int TIMEOUT_SECONDS = 5;
    private const int MAX_DURATION_SECONDS = 15;

    private HttpClientInterface $http;

    public function __construct(
        #[Target('github.client')]
        HttpClientInterface $http,
        private AppJwt $jwt,
        private ExpectedInstallation $expected,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $appId,
        private string $installationId,
        #[SensitiveParameter]
        private string $privateKey,
    ) {
        $this->http = $http->withOptions(['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::MAX_DURATION_SECONDS, 'max_retries' => 0]);
    }

    public function run(): ForgeConnectionVerdict
    {
        $setting = $this->settingProblem();
        if ($setting instanceof ForgeConnectionVerdict) {
            return $setting;
        }
        if ('' === trim($this->appId)) {
            return ForgeConnectionVerdict::passed();
        }

        return $this->readBack();
    }

    // An empty App ID with an installation ID or a key still set is a half-cleared App, not a demo: the demo has none of the three.
    private function settingProblem(): ?ForgeConnectionVerdict
    {
        $appEmpty = '' === trim($this->appId);
        $others = '' !== trim($this->installationId) || '' !== trim($this->privateKey);
        if ($appEmpty && $others) {
            return ForgeConnectionVerdict::failed(
                'GITHUB_APP_ID is empty while GITHUB_APP_INSTALLATION_ID or GITHUB_APP_PRIVATE_KEY is still set',
                'set GITHUB_APP_ID to the App ID, or empty the installation ID and the private key as well to run without an App',
            );
        }
        if (!$appEmpty && '' === trim($this->installationId)) {
            return ForgeConnectionVerdict::failed('GITHUB_APP_INSTALLATION_ID is empty', 'set GITHUB_APP_INSTALLATION_ID to the installation ID of the App');
        }

        return null;
    }

    private function readBack(): ForgeConnectionVerdict
    {
        try {
            // Parsed before any call, because the settings are lazy and a wrong one must fail without reaching GitHub.
            if ([] === $this->expected->permissions) {
                throw new InvalidArgumentException('GITHUB_APP_PERMISSIONS names no permission.');
            }
            (void) $this->jwt->sign();
        } catch (InvalidArgumentException $unreadable) {
            return ForgeConnectionVerdict::failed(
                'the App settings cannot be read ('.$unreadable->getMessage().')',
                'correct GITHUB_APP_PERMISSIONS as name:level pairs and GITHUB_APP_REPOSITORIES as owner/repo names',
            );
        } catch (InstallationTokenNotMinted) {
            return ForgeConnectionVerdict::failed(
                'the private key cannot sign (it cannot be read or is not an RSA key)',
                'set GITHUB_APP_PRIVATE_KEY to the PEM private key of the App',
            );
        }

        try {
            (void) $this->readBackOf()->check();
        } catch (InstallationDoesNotMatchConfiguration $mismatch) {
            return ForgeConnectionVerdict::failed(
                'the installation does not match the configuration ('.$mismatch->getMessage().')',
                'change the permissions or repositories of the App installation on GitHub, or GITHUB_APP_PERMISSIONS and GITHUB_APP_REPOSITORIES, so they agree',
            );
        } catch (InstallationNotReadBack $notRead) {
            return $this->cannotRun($notRead->getMessage());
        } catch (ForgeRateLimited $limited) {
            return $this->cannotRun($limited->getMessage());
        }

        return ForgeConnectionVerdict::passed();
    }

    private function cannotRun(string $reason): ForgeConnectionVerdict
    {
        return ForgeConnectionVerdict::failed(
            'cannot run ('.$reason.')',
            'check GITHUB_APP_ID, GITHUB_APP_INSTALLATION_ID and GITHUB_APP_PRIVATE_KEY, and that GitHub is reachable from this install',
        );
    }

    private function readBackOf(): InstallationReadBack
    {
        $readings = new RateLimitReadings(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
        $tokens = new InstallationTokens(new InstallationTokenMinter($this->jwt, $this->http, $this->logger), $this->installationId, $this->clock);

        return new InstallationReadBack(
            new InstallationReader($this->jwt, $tokens, new GitHubCalls($this->http, $readings, $tokens)),
            $this->expected,
            $this->logger,
        );
    }
}
