<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Assert;

trait UsesSandboxes
{
    /** @var list<Sandbox> */
    private array $sandboxes = [];

    // Shared by the phpstan runs of a class, because dumping this repository's test container takes seconds, no fixture changes it, and the container is baked into the folder it was dumped in.
    private static ?Sandbox $kernelBorrowing = null;

    public static function tearDownAfterClass(): void
    {
        self::$kernelBorrowing?->remove();
        self::$kernelBorrowing = null;
        parent::tearDownAfterClass();
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
        $run = $this->sandboxFor($gate, $fixture)->check('--only='.$gate);

        Assert::assertSame(1, $run->exitCode(), $run->output());
        Assert::assertTrue($run->failed($gate), $run->output());
        Assert::assertStringContainsString($expectedFinding, $run->findingsOf($gate));
    }

    final protected function assertAcceptedBy(string $gate, string $fixture): void
    {
        $run = $this->sandboxFor($gate, $fixture)->check('--only='.$gate);

        Assert::assertSame(0, $run->exitCode(), $run->output());
        Assert::assertTrue($run->passed($gate), $run->output());
    }

    private function sandboxFor(string $gate, string $fixture): Sandbox
    {
        if ('phpstan' !== $gate) {
            return $this->sandboxWith($fixture);
        }

        self::$kernelBorrowing ??= Sandbox::empty();
        self::$kernelBorrowing->replaceFixtureWith($fixture);
        self::$kernelBorrowing->borrowKernel();

        return self::$kernelBorrowing;
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
