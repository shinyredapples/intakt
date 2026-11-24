<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The real trust check over a recorded GitHub, because a stub of it would not show that the permission answer decides who steers.
final class TrustChecks
{
    public static function over(RecordedTrust $github): SteeringTrustCheck
    {
        return self::wiring($github->client())->check;
    }

    public static function wiring(HttpClientInterface $http): TrustWiring
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $calls = new GitHubCalls($http, FreshReadings::at($clock), $tokens);
        $checked = new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($jwt, $tokens, $calls), $expected, new NullLogger()), $tokens);
        $answers = new TrustAnswers($calls);
        $identities = new SteeringIdentities($answers, $jwt, RecordedTrust::MAINTAINER_SLUG);

        return new TrustWiring(new SteeringTrustCheck($identities, $answers, $checked), $identities, $calls, $checked);
    }
}
