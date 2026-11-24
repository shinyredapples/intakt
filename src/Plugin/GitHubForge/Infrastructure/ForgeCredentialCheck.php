<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Core\Doctor\Contract\HealthCheck;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use InvalidArgumentException;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The start's own read-back, so the doctor and the start never disagree; built per run over a scratch budget store and a quiet log, because the doctor must not move the workers' budget or pause, nor print a trace. The failure messages carry a status or a class only, so no key leaks.
final readonly class ForgeCredentialCheck implements HealthCheck
{
    private const string DEADLINE = '+15 seconds';
    private const string FIX = ' Check the GitHub App id, private key and installation id in your settings, and that the host can reach api.github.com.';

    public function __construct(
        private AppJwt $jwt,
        private HttpClientInterface $http,
        private RateLimitReadings $readings,
        private ExpectedInstallation $expected,
        private ClockInterface $clock,
        private string $appId,
        private string $installationId,
        #[SensitiveParameter]
        private string $privateKey,
    ) {
    }

    public function name(): string
    {
        return 'GitHub App credential';
    }

    public function run(): CheckResult
    {
        if ('' === trim($this->appId)) {
            return $this->withoutApp();
        }

        return $this->readInstallation();
    }

    // No App at all (the demo) has nothing to check; an installation id or key without the App id is a half-cleared setting the start would silently treat as no App.
    private function withoutApp(): CheckResult
    {
        if ('' === trim($this->installationId.$this->privateKey)) {
            return CheckResult::passed();
        }

        return CheckResult::failed('GITHUB_APP_ID is empty while GITHUB_APP_INSTALLATION_ID or GITHUB_APP_PRIVATE_KEY is set. Set GITHUB_APP_ID, or clear all three to run without an App.');
    }

    private function readInstallation(): CheckResult
    {
        try {
            // The workers' pause is read and honored, never written.
            $this->readings->refuseWhileLimited($this->installationId, RateLimitReadings::CORE);
            (void) $this->readBack()->check();
        } catch (ForgeRateLimited $limited) {
            return CheckResult::failed('GitHub rate-limits this installation until '.$limited->resetsAt->format(DATE_ATOM).'. Run the doctor again after that.');
        } catch (ReportableFailure $failure) {
            return CheckResult::failed($failure->getMessage().self::FIX);
        } catch (InvalidArgumentException $invalid) {
            // The expected installation is parsed from the settings on first use, so a bad setting surfaces here.
            return CheckResult::failed($invalid->getMessage().' Fix GITHUB_APP_PERMISSIONS and GITHUB_APP_REPOSITORIES in your settings.');
        }

        return CheckResult::passed();
    }

    private function readBack(): InstallationReadBack
    {
        $http = new DeadlineHttpClient($this->http, $this->clock, $this->clock->now()->modify(self::DEADLINE));
        $quiet = new NullLogger();
        $tokens = new InstallationTokens(new InstallationTokenMinter($this->jwt, $http, $quiet), $this->installationId, $this->clock);
        $scratch = new RateLimitReadings(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));

        return new InstallationReadBack(new InstallationReader($this->jwt, $tokens, new GitHubCalls($http, $scratch, $tokens)), $this->expected, $quiet);
    }
}
