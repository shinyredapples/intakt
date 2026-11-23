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

    final protected function check(Workspace $workspace, PhpFileSelection $selection): GateResult
    {
        if (!$workspace->hasTool($this->tool())) {
            return GateResult::failedBecause($this->name(), 'missing tool '.$this->tool());
        }

        try {
            $environment = $this->environment($selection);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $result = $workspace->command([$workspace->tool($this->tool()), ...$this->arguments($workspace, $selection->files)], $environment);

        return $this->resultOf($result, $workspace);
    }

    /**
     * @return array<string, string|false>
     */
    protected function environment(PhpFileSelection $selection): array
    {
        return [];
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
