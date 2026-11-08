<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ComposerAuditGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ComposerAuditGate::class)]
final class ComposerAuditGateTest extends SandboxTestCase
{
    private const string KNOWN_ADVISORY = 'PKSA-hr4y-jwk2-1yb9';

    // Because the fixtures serve their own advisories, a proxy nobody listens on turns any request to Packagist into a failed test.
    private const array OFFLINE = ['http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => '', 'NO_PROXY' => ''];

    public function testALockedDependencyWithAKnownAdvisoryFailsTheAudit(): void
    {
        $run = $this->sandboxWith('vulnerable-dependency')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('symfony/http-kernel '.self::KNOWN_ADVISORY.' CVE-2022-24894', $run->findingsOf('audit'));
    }

    public function testAnAuditIgnoreInComposerJsonFailsTheAudit(): void
    {
        $run = $this->sandboxWith('audit-ignore')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('config.audit.ignore', $run->findingsOf('audit'));
    }

    public function testAnAbandonedPackageInTheLockFailsTheAudit(): void
    {
        $run = $this->sandboxWith('abandoned-dependency')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('intakt/retired-package is abandoned', $run->findingsOf('audit'));
    }

    // Because Composer compares the release time with its own clock, a release in the far future stays inside the period.
    public function testALockedReleaseInsideTheCooldownFailsTheAuditNamingItsVersionAndAvailability(): void
    {
        $run = $this->sandboxWith('cooldown-dependency')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('intakt/fresh-package 1.0.0 published 2999-01-01T00:00:00+00:00 is in the cooldown, available in ', $run->findingsOf('audit'));
    }

    public function testALockedReleaseOlderThanTheCooldownPassesTheAudit(): void
    {
        $sandbox = $this->sandboxWith('cooldown-dependency');
        $sandbox->write('composer.lock', str_replace('2999-01-01', '2020-01-01', (string) file_get_contents($sandbox->root.'/composer.lock')));

        $run = $sandbox->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(0, $run->exitCode(), $run->output());
    }

    public function testAGlobalComposerConfigCannotIgnoreAnAdvisory(): void
    {
        $sandbox = $this->sandboxWith('vulnerable-dependency');
        $home = $this->emptySandbox();
        $home->write('config.json', '{"config": {"audit": {"ignore": ["'.self::KNOWN_ADVISORY.'"], "abandoned": "ignore"}}}'."\n");

        $run = $sandbox->checkWith(['COMPOSER_HOME' => $home->root, ...self::OFFLINE], '--only=audit');

        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString(self::KNOWN_ADVISORY, $run->findingsOf('audit'));
    }

    public function testTheFastRunStillAuditsWhenNoDependencyFileChanged(): void
    {
        $sandbox = $this->sandboxWith('vulnerable-dependency');
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        $run = $sandbox->checkWith(self::OFFLINE, '--only=audit', '--fast', '--base=main');

        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('symfony/http-kernel', $run->findingsOf('audit'));
    }

    public function testTheAuditFailsWhenThereIsNoLockFileToAudit(): void
    {
        $run = $this->emptySandbox()->checkWith(self::OFFLINE, '--only=audit');

        self::assertTrue($run->failed('audit'), $run->output());
    }
}
