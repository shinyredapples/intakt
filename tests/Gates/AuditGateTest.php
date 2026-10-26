<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ComposerAuditGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ComposerAuditGate::class)]
final class AuditGateTest extends SandboxTestCase
{
    private const string IGNORED_ADVISORY = 'PKSA-hr4y-jwk2-1yb9';

    public function testALockedDependencyWithAKnownAdvisoryFailsTheAudit(): void
    {
        $this->assertRejectedBy('audit', 'vulnerable-dependency', 'symfony/http-kernel PKSA-');
    }

    public function testAnAuditIgnoreInComposerJsonFailsTheAudit(): void
    {
        $this->assertRejectedBy('audit', 'audit-ignore', 'config.audit.ignore');
    }

    public function testAnAbandonedPackageInTheLockFailsTheAudit(): void
    {
        $this->assertRejectedBy('audit', 'abandoned-dependency', 'intakt/retired-package is abandoned');
    }

    public function testAGlobalComposerConfigCannotIgnoreAnAdvisory(): void
    {
        $sandbox = $this->sandboxWith('vulnerable-dependency');
        $home = $this->emptySandbox();
        $home->write('config.json', '{"config": {"audit": {"ignore": ["'.self::IGNORED_ADVISORY.'"], "abandoned": "ignore"}}}'."\n");

        $run = $sandbox->checkWith(['COMPOSER_HOME' => $home->root], '--only=audit');

        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString(self::IGNORED_ADVISORY, $run->findingsOf('audit'));
    }

    public function testTheFastRunStillAuditsWhenNoDependencyFileChanged(): void
    {
        $sandbox = $this->sandboxWith('vulnerable-dependency');
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        $run = $sandbox->check('--only=audit', '--fast', '--base=main');

        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('symfony/http-kernel', $run->findingsOf('audit'));
    }

    public function testTheAuditFailsWhenThereIsNoLockFileToAudit(): void
    {
        $run = $this->emptySandbox()->check('--only=audit');

        self::assertTrue($run->failed('audit'), $run->output());
    }
}
