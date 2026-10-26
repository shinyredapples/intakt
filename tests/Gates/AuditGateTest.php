<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

final class AuditGateTest extends SandboxTestCase
{
    public function testALockedDependencyWithAKnownAdvisoryFailsTheAudit(): void
    {
        $this->assertRejectedBy('audit', 'vulnerable-dependency', 'symfony/http-kernel PKSA-');
    }

    public function testAnAuditIgnoreInComposerJsonFailsTheAudit(): void
    {
        $this->assertRejectedBy('audit', 'audit-ignore', 'config.audit.ignore');
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
