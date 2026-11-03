<?php

declare(strict_types=1);

namespace Intakt\Gates;

use InvalidArgumentException;

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

        return $this->runGatesWhenToolsExist($options);
    }

    private function runGatesWhenToolsExist(Options $options): int
    {
        $gates = $this->selectedGates($options);
        $names = array_map(static fn (Gate $gate): string => $gate->name(), $gates);
        $missing = new RequiredTools($options->root ?? $this->home)->missingFor($names);

        if ([] !== $missing) {
            $this->print(['error' => 'missing tools', 'missing' => $missing]);

            return 2;
        }

        return $this->runGates($gates, $options);
    }

    /**
     * @param list<Gate> $gates
     */
    private function runGates(array $gates, Options $options): int
    {
        $workspace = new Workspace($options->root ?? $this->home, $this->home, $options->mode, $options->base);
        $failed = [];

        foreach ($gates as $gate) {
            $result = $this->resultOf($gate, $workspace);
            fwrite(STDOUT, $result->toJson()."\n");

            if (!$result->isPassing()) {
                $failed[] = $gate->name();
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
            new HighlightsUpToDateGate(new HighlightsRows(new FencedCodeBlocks())),
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
