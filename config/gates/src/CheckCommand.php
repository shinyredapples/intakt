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
        // Because CI runs these gates and the rest in two containers, each on its own copy, and takes the split and the order from here.
        if (['--static-gates'] === $arguments) {
            $this->print(['static' => $this->staticGateNames(), 'order' => $this->gateNames()]);

            return 0;
        }

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

        $slots = $this->slots();

        if (!$slots instanceof GateSlots) {
            $this->print(['error' => GateSlots::VARIABLE.' names no number of gates to run at once from 1 to '.GateSlots::MOST]);

            return 2;
        }

        return $this->runGatesWhenToolsExist($options, $arguments, $slots);
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

        if (LowPriority::isAskedIn(getenv())) {
            new LowPriority()->take();
        }

        $wholeResultFile = $this->variable(GateChildren::FULL_RESULT_VARIABLE);
        // Because a gate may start a nested bin/check or gate child of its own, none of them may write in place of this child's whole result.
        putenv(GateChildren::FULL_RESULT_VARIABLE);
        unset($_ENV[GateChildren::FULL_RESULT_VARIABLE], $_SERVER[GateChildren::FULL_RESULT_VARIABLE]);

        $result = $this->resultOf($gate, new Workspace($options->root ?? $this->home, $this->home, $options->mode, $options->base));
        fwrite(STDOUT, $result->toJson()."\n");

        if (null !== $wholeResultFile) {
            $this->keep($result, $wholeResultFile);
        }

        return $result->isPassing() ? 0 : 1;
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

    private function variable(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
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

    // Because the file is the parent's hand-off, the child only creates it and never writes through anything already in its place, a link included.
    private function keep(GateResult $result, string $file): void
    {
        $handle = file_exists($file) || is_link($file) ? false : fopen($file, 'x');

        if (false === $handle) {
            return;
        }

        fwrite($handle, $result->toFullJson());
        fclose($handle);
    }

    /**
     * @return list<string>
     */
    private function staticGateNames(): array
    {
        return array_values(array_map(static fn (Gate $gate): string => $gate->name(), array_filter($this->gates(null), static fn (Gate $gate): bool => !$gate instanceof RunsTheChangesCode)));
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
            new EnvironmentGate(PhpRuntime::current()),
            new CodingStandardsGate(),
            new ContainerDumpingGate(new StaticAnalysisGate()),
            new RectorGate(),
            new ArchitectureGate(),
            new CommentsGate(),
            new ComposerAuditGate(),
            new TestsGate(),
            new WorkingTreeSecretsGate(),
            new HistorySecretsGate(),
            new CommitSubjectsGate($testMerge),
            new LongDashesGate(),
            new HighlightsGate(new FencedCodeBlocks(), new HighlightFragments()),
            new ReproductionRowsGate(new FencedCodeBlocks(), new CheckedReports(new FencedCodeBlocks(), new NativeClock()), new HighlightFragments()),
            new ContainerLintGate(),
            new RatchetListsGate(),
            new WorkflowsGate(),
            new LeftUndoneGate(),
            new DocsGate(new MarkdownReader(new MarkdownCodeBlocks(new FencedCodeBlocks(), new MarkdownListIndents()))),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function print(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    // Because the test workers count against the same processors as the gates, the parent sizes them as the tests gate will; a refused count fails that gate, so it counts as one.
    private function slots(): ?GateSlots
    {
        $testWorkers = TestWorkerCount::chosen($this->variable(TestWorkerCount::VARIABLE), Processors::status(), Processors::cpuMax()) ?? 1;

        $memoryLimits = [...new OwnCgroup()->fileAndAncestors('memory.max'), ...new OwnCgroup()->fileAndAncestors('memory.high')];

        $byDefault = GateSlots::byDefault(Processors::available(Processors::status(), Processors::cpuMax()), $memoryLimits, $testWorkers);

        return GateSlots::chosen($this->variable(GateSlots::VARIABLE), $byDefault, $testWorkers);
    }

    /**
     * @param list<string> $arguments
     */
    private function runGatesWhenToolsExist(Options $options, array $arguments, GateSlots $slots): int
    {
        $selected = $this->selectedGates($options);
        $names = array_map(static fn (Gate $gate): string => $gate->name(), $selected);
        $missing = new RequiredTools($options->root ?? $this->home)->missingToRunEachInItsOwnProcess($names);

        if ([] !== $missing) {
            $this->print(['error' => 'missing tools', 'missing' => $missing]);

            return 2;
        }

        $runningTheChangesCode = array_map(static fn (Gate $gate): string => $gate->name(), array_values(array_filter($selected, static fn (Gate $gate): bool => $gate instanceof RunsTheChangesCode)));

        $root = $options->root ?? $this->home;
        $children = new GateChildren($this->home, $root, $slots, $runningTheChangesCode);
        $scratch = $children->scratchOutsideTheTree();
        $reports = $children->reportsOf($names, $arguments, $scratch);

        // Because without the scratch folder no gate could keep its whole result, a report then would hold only the lines, so none is written.
        return $this->runGates($reports, $scratch instanceof ScratchDirectory ? new FullReport($root, $reports) : null);
    }

    /**
     * @param list<GateReport> $reports
     */
    private function runGates(array $reports, ?FullReport $fullReport): int
    {
        $failed = $this->reportOn($reports);
        $summary = ['result' => [] === $failed ? 'pass' : 'fail', 'failed' => $failed];
        $file = $fullReport?->write();

        $this->print(null === $file ? $summary : [...$summary, 'report' => $file]);

        return [] === $failed ? 0 : 1;
    }

    /**
     * @param list<GateReport> $reports
     *
     * @return list<string>
     */
    // Because the verdict, the failed list and the exit code come from the gate lines alone, before any report is written.
    private function reportOn(array $reports): array
    {
        $failed = [];

        foreach ($reports as $report) {
            fwrite(STDOUT, $report->line."\n");

            if (!$report->isPassing()) {
                $failed[] = $report->gate;
            }
        }

        return $failed;
    }
}
