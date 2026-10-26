<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\TestCase;

abstract class SandboxTestCase extends TestCase
{
    /** @var list<Sandbox> */
    private array $sandboxes = [];

    protected function setUp(): void
    {
        putenv('INTAKT_GITLEAKS='.trim((string) shell_exec('command -v gitleaks')));
    }

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $sandbox) {
            $sandbox->remove();
        }
    }

    final protected function skipWhenPermissionsAreIgnored(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('This user ignores file permissions (superuser).');
        }
    }

    final protected function sandboxWith(string $fixture): Sandbox
    {
        return $this->keep(Sandbox::withFixture($fixture));
    }

    final protected function emptySandbox(): Sandbox
    {
        return $this->keep(Sandbox::empty());
    }

    final protected function assertRejectedBy(string $gate, string $fixture, string $expectedFinding): void
    {
        $run = $this->sandboxWith($fixture)->check('--only='.$gate);

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed($gate), $run->output());
        self::assertStringContainsString($expectedFinding, $run->findingsOf($gate));
    }

    final protected function assertAcceptedBy(string $gate, string $fixture): void
    {
        $run = $this->sandboxWith($fixture)->check('--only='.$gate);

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed($gate), $run->output());
    }

    private function keep(Sandbox $sandbox): Sandbox
    {
        $this->sandboxes[] = $sandbox;

        return $sandbox;
    }
}
