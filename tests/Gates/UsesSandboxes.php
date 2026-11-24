<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Assert;

trait UsesSandboxes
{
    /** @var list<Sandbox> */
    private array $sandboxes = [];

    // The class owns one sandbox for its phpstan runs, because dumping this repository's test container takes seconds, no fixture changes it, and the container is baked into the folder it was dumped in. Created fresh before the class, removed after it.
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        Sandbox::ownedBy(static::class)->remove();
    }

    public static function tearDownAfterClass(): void
    {
        Sandbox::ownedBy(static::class)->remove();
        parent::tearDownAfterClass();
    }

    /**
     * @param array<string, non-empty-list<string>> $fixtureAndFindingsOfEachCase
     *
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    final protected static function casesOfOnePhpstanRun(array $fixtureAndFindingsOfEachCase, string $parent = ''): iterable
    {
        return self::casesOfOneRunWith($fixtureAndFindingsOfEachCase, $parent, static fn (Sandbox $sandbox) => $sandbox->borrowKernel());
    }

    /**
     * @param array<string, non-empty-list<string>> $fixtureAndFindingsOfEachCase
     *
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    final protected static function casesOfOnePhpstanRunOnTheFixtureKernel(array $fixtureAndFindingsOfEachCase, string $parent = ''): iterable
    {
        return self::casesOfOneRunWith($fixtureAndFindingsOfEachCase, $parent, static fn (Sandbox $sandbox) => $sandbox->bootFixtureKernel());
    }

    /**
     * @param array<string, non-empty-list<string>> $fixtureAndFindingsOfEachCase
     * @param \Closure(Sandbox): void              $bootKernel
     *
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    private static function casesOfOneRunWith(array $fixtureAndFindingsOfEachCase, string $parent, \Closure $bootKernel): iterable
    {
        // PHPUnit calls a provider once for all its cases, so one run answers every fixture, each in its own folder (under a parent such as src/ when the rule judges only that folder, or its cases would pass unjudged), and a case still runs alone under --filter
        $sandbox = Sandbox::withFixturesInFoldersUnder($parent, ...array_unique(array_column($fixtureAndFindingsOfEachCase, 0)));
        $bootKernel($sandbox);

        try {
            $run = $sandbox->check('--only=phpstan');
        } finally {
            $sandbox->remove();
        }

        foreach ($fixtureAndFindingsOfEachCase as $name => $fixtureAndFindings) {
            yield $name => [$run, $parent.array_shift($fixtureAndFindings), ...$fixtureAndFindings];
        }
    }

    final protected function assertFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $findings = array_values(array_filter(explode("\n", $run->findingsOf('phpstan')), static fn (string $finding): bool => '' !== $finding));
        // Because a fixture that must pass reports nothing, a run that judged no file at all would otherwise pass it too.
        Assert::assertTrue($run->passed('phpstan') || $run->failed('phpstan'), $run->output());
        // The gate shows only its first findings, so an omitted one could be the finding a fixture that must pass is judged by.
        Assert::assertFalse($run->failed('phpstan') && [] === $findings, $run->output());
        Assert::assertSame('0', $run->omittedOf('phpstan'), $run->output());
        Assert::assertSame([], array_values(array_filter($findings, static fn (string $finding): bool => 1 !== preg_match('~^\S+:\d+ ~', $finding))), $run->output());

        $inItsFolder = array_values(array_filter($findings, static fn (string $finding): bool => str_starts_with($finding, $fixture.'/')));
        Assert::assertCount(count($expectedFindings), $inItsFolder, $run->output());

        foreach (array_values($expectedFindings) as $index => $expectedFinding) {
            Assert::assertStringContainsString($fixture.'/'.$expectedFinding, $inItsFolder[$index] ?? '');
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

        $sandbox = Sandbox::ownedBy(static::class);
        $sandbox->replaceFixtureWith($fixture);
        $sandbox->borrowKernel();

        return $sandbox;
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
