<?php

declare(strict_types=1);

namespace Intakt\Gates;

abstract readonly class PhpFilesToolGate extends PhpFilesGate
{
    abstract protected function tool(): string;

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    abstract protected function arguments(Workspace $workspace, array $files): array;

    /**
     * @return list<string>
     */
    abstract protected function findings(ProcessResult $result): array;

    final protected function check(Workspace $workspace, array $files): GateResult
    {
        if (!$workspace->hasTool($this->tool())) {
            return GateResult::failedBecause($this->name(), 'missing tool '.$this->tool());
        }

        $result = $workspace->command([$workspace->tool($this->tool()), ...$this->arguments($workspace, $files)]);

        return $this->resultOf($result, $workspace);
    }

    private function resultOf(ProcessResult $result, Workspace $workspace): GateResult
    {
        if ($result->succeeded()) {
            return GateResult::passed($this->name());
        }

        $findings = array_map(static fn (string $finding): string => str_replace($workspace->root.'/', '', $finding), $this->findings($result));

        if ([] === $findings) {
            $findings = ['tool failed: '.$result->firstErrorLine()];
        }

        return GateResult::withFindings($this->name(), $findings);
    }
}
