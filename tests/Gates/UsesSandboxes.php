<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Assert;

trait UsesSandboxes
{
    /** @var list<Sandbox> */
    private array $sandboxes = [];

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

        Assert::assertSame(1, $run->exitCode(), $run->output());
        Assert::assertTrue($run->failed($gate), $run->output());
        Assert::assertStringContainsString($expectedFinding, $run->findingsOf($gate));
    }

    final protected function assertAcceptedBy(string $gate, string $fixture): void
    {
        $run = $this->sandboxWith($fixture)->check('--only='.$gate);

        Assert::assertSame(0, $run->exitCode(), $run->output());
        Assert::assertTrue($run->passed($gate), $run->output());
    }

    private function removeSandboxes(): void
    {
        foreach ($this->sandboxes as $sandbox) {
            $sandbox->remove();
        }

        $this->sandboxes = [];
    }

    private function keep(Sandbox $sandbox): Sandbox
    {
        $this->sandboxes[] = $sandbox;

        return $sandbox;
    }
}
