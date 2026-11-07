<?php

declare(strict_types=1);

namespace Intakt\Gates;

use InvalidArgumentException;
use Symfony\Component\Clock\NativeClock;

final readonly class CheckCommand
{
    public function __construct(private string $home)
    {
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

        return $this->runGatesWhenToolsExist($options, $arguments);
    }

    /**
     * @param list<string> $arguments
     */
    public function runGate(string $name, array $arguments): int
    {
        try {
            $options = Options::fromArguments($arguments);
        } catch (InvalidArgumentException $exception) {
            $this->print(['error' => $exception->getMessage()]);

            return 2;
        }

        // Because the child gets the arguments of bin/check, it finds its gate in the same selection the parent made.
        $gate = array_find($this->selectedGates($options), static fn (Gate $gate): bool => $gate->name() === $name);

        if (!$gate instanceof Gate) {
            $this->print(['error' => 'unknown gate '.$name]);

            return 2;
        }

        $result = $this->resultOf($gate, new Workspace($options->root ?? $this->home, $this->home, $options->mode, $options->base));
        fwrite(STDOUT, $result->toJson()."\n");

        return $result->isPassing() ? 0 : 1;
    }

    /**
     * @param list<string> $arguments
     */
    private function runGatesWhenToolsExist(Options $options, array $arguments): int
    {
        $names = array_map(static fn (Gate $gate): string => $gate->name(), $this->selectedGates($options));
        $missing = new RequiredTools($options->root ?? $this->home)->missingToRunEachInItsOwnProcess($names);

        if ([] !== $missing) {
            $this->print(['error' => 'missing tools', 'missing' => $missing]);

            return 2;
        }

        return $this->runGates($names, $arguments, $options->root ?? $this->home);
    }

    /**
     * @param list<string> $names
     * @param list<string> $arguments
     */
    private function runGates(array $names, array $arguments, string $root): int
    {
        $failed = [];

        foreach (new GateChildren($this->home, $root)->reportsOf($names, $arguments) as $report) {
            fwrite(STDOUT, $report->line."\n");

            if (!$report->isPassing()) {
                $failed[] = $report->gate;
            }
        }

        $this->print(['result' => [] === $failed ? 'pass' : 'fail', 'failed' => $failed]);

        return [] === $failed ? 0 : 1;
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
        // Because the journeys boot whole installs and run on their own, never as part of the gates every change passes.
        if (Mode::Journeys === $options->mode) {
            return [new JourneysGate()];
        }

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
            new ReproductionRowsGate(new FencedCodeBlocks(), new CheckedReports(new FencedCodeBlocks(), new NativeClock())),
            new ContainerLintGate(),
            new RatchetListsGate(),
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
