<?php

declare(strict_types=1);

namespace Intakt\Gates;

abstract readonly class PhpFilesGate implements Gate
{
    /**
     * @param non-empty-list<string> $files
     */
    abstract protected function check(Workspace $workspace, array $files): GateResult;

    final public function run(Workspace $workspace): GateResult
    {
        try {
            $files = $workspace->phpFiles();
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        if ([] === $files) {
            return GateResult::passedBecause($this->name(), 'no changed PHP files in scope');
        }

        return $this->check($workspace, $files);
    }
}
