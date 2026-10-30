<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ComposerAuditGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ComposerAuditGate::class)]
final class AuditGateTest extends SandboxTestCase
{
    private const string IGNORED_ADVISORY = 'PKSA-hr4y-jwk2-1yb9';

    // Because the fixtures serve their own advisories, a proxy nobody listens on turns any request to Packagist into a failed test.
    private const array OFFLINE = ['http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => '', 'NO_PROXY' => ''];

    public function testALockedDependencyWithAKnownAdvisoryFailsTheAudit(): void
    {
        $run = $this->sandboxWith('vulnerable-dependency')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('symfony/http-kernel '.self::IGNORED_ADVISORY.' CVE-2022-24894', $run->findingsOf('audit'));
    }

    public function testAnAuditIgnoreInComposerJsonFailsTheAudit(): void
    {
        $run = $this->sandboxWith('audit-ignore')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('config.audit.ignore', $run->findingsOf('audit'));
    }

    public function testAnAbandonedPackageInTheLockFailsTheAudit(): void
    {
        $run = $this->sandboxWith('abandoned-dependency')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('intakt/retired-package is abandoned', $run->findingsOf('audit'));
    }

    public function testAGlobalComposerConfigCannotIgnoreAnAdvisory(): void
    {
        $sandbox = $this->sandboxWith('vulnerable-dependency');
        $home = $this->emptySandbox();
        $home->write('config.json', '{"config": {"audit": {"ignore": ["'.self::IGNORED_ADVISORY.'"], "abandoned": "ignore"}}}'."\n");

        $run = $sandbox->checkWith(['COMPOSER_HOME' => $home->root, ...self::OFFLINE], '--only=audit');

        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString(self::IGNORED_ADVISORY, $run->findingsOf('audit'));
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
