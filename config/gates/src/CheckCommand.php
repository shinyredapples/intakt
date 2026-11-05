<?php

declare(strict_types=1);

namespace Intakt\Gates;

use InvalidArgumentException;

final readonly class CheckCommand
{
    // Because a test fakes the child that runs one gate; without it each child is bin/check itself.
    /**
     * @param list<string>|null $gateCommand
     */
    public function __construct(
        private string $home,
        private ?array $gateCommand = null,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        try {
            $options = Options::fromArguments($arguments);
        } catch (InvalidArgumentException $exception) {
            $this->print(['error' => $exception->getMessage()]);

            return 2;
        }

        $unknown = array_diff([...$options->only, ...$options->skip], $this->gateNames());

        if ([] !== $unknown) {
            $this->print(['error' => 'unknown gate '.implode(',', $unknown)]);

            return 2;
        }

        if (null !== $options->gate) {
            return $this->runOneGateHere($options->gate, $options);
        }

        return $this->runGatesWhenToolsExist($options, $arguments);
    }

    private function runOneGateHere(string $name, Options $options): int
    {
        $gate = array_find($this->gates($options->testMerge), static fn (Gate $gate): bool => $gate->name() === $name);

        if (!$gate instanceof Gate) {
            $this->print(['error' => 'unknown gate '.$name]);

            return 2;
        }

        $workspace = new Workspace($options->root ?? $this->home, $this->home, $options->mode, $options->base);
        $result = $this->resultOf($gate, $workspace);
        fwrite(STDOUT, $result->toJson()."\n");

        return $result->isPassing() ? 0 : 1;
    }

    /**
     * @param list<string> $arguments
     */
    private function runGatesWhenToolsExist(Options $options, array $arguments): int
    {
        $gates = $this->selectedGates($options);
        $names = array_map(static fn (Gate $gate): string => $gate->name(), $gates);
        $missing = new RequiredTools($options->root ?? $this->home)->missingFor($names);

        if ([] !== $missing) {
            $this->print(['error' => 'missing tools', 'missing' => $missing]);

            return 2;
        }

        try {
            $slots = GateSlots::fromEnvironment();
        } catch (InvalidArgumentException $exception) {
            $this->print(['error' => $exception->getMessage()]);

            return 2;
        }

        $spreading = array_values(array_map(
            static fn (Gate $gate): string => $gate->name(),
            array_filter($gates, static fn (Gate $gate): bool => $gate instanceof SpreadsOverEveryProcessor),
        ));

        return $this->runGates($names, $arguments, new ProcessSlots($slots, $spreading));
    }

    /**
     * @param list<string> $names
     * @param list<string> $arguments
     */
    private function runGates(array $names, array $arguments, ProcessSlots $slots): int
    {
        $children = new ProcessRunner()->runAtMost($slots, $this->childCommands($names, $arguments), $this->workingDirectory());
        $failed = [];

        foreach ($names as $name) {
            $report = ChildReport::of($name, $children[$name]);
            fwrite(STDOUT, $report->line."\n");

            if (!$report->isPassing()) {
                $failed[] = $name;
            }
        }

        $this->print(['result' => [] === $failed ? 'pass' : 'fail', 'failed' => $failed]);

        return [] === $failed ? 0 : 1;
    }

    /**
     * @param list<string> $names
     * @param list<string> $arguments
     *
     * @return array<string, array{list<string>, array<string, string|false>}>
     */
    private function childCommands(array $names, array $arguments): array
    {
        $tests = new TestsGate()->name();
        $commands = [];

        // Because the tests gate takes longest by far, it starts first and never waits for a free slot.
        foreach ([...array_intersect($names, [$tests]), ...array_diff($names, [$tests])] as $name) {
            $commands[$name] = [[...$this->gateCommand ?? [PHP_BINARY, $this->home.'/bin/check'], ...$arguments, '--gate='.$name], []];
        }

        return $commands;
    }

    private function workingDirectory(): string
    {
        $directory = getcwd();

        return false === $directory ? $this->home : $directory;
    }

    private function resultOf(Gate $gate, Workspace $workspace): GateResult
    {
        // Because a gate that reads git cannot run then, and the reason belongs on its own line like any other failure.
        try {
            return $gate->run($workspace);
        } catch (RepositoryAttributesRefused $refused) {
            return GateResult::failedBecause($gate->name(), $refused->getMessage());
        }
    }

    /**
     * @return list<Gate>
     */
    private function selectedGates(Options $options): array
    {
        return array_values(array_filter(
            $this->gates($options->testMerge),
            static fn (Gate $gate): bool => ([] === $options->only || in_array($gate->name(), $options->only, true)) && !in_array($gate->name(), $options->skip, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function gateNames(): array
    {
        return array_map(static fn (Gate $gate): string => $gate->name(), $this->gates(null));
    }

    /**
     * @return list<Gate>
     */
    private function gates(?string $testMerge): array
    {
        return [
            new CodingStandardsGate(),
            new StaticAnalysisGate(),
            new RectorGate(),
            new ArchitectureGate(),
            new CommentsGate(),
            new ComposerAuditGate(),
            new TestsGate(),
            new WorkingTreeSecretsGate(),
            new HistorySecretsGate(),
            new CommitSubjectsGate($testMerge),
            new LongDashesGate(),
            new HighlightsGate(new FencedCodeBlocks()),
            new ReproductionRowsGate(new FencedCodeBlocks()),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function print(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }
}
