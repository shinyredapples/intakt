<?php

declare(strict_types=1);

namespace Intakt\Gates;

abstract readonly class PhpFilesGate implements Gate
{
    final public function run(Workspace $workspace): GateResult
    {
        try {
            $selection = $workspace->phpFileSelection();
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        if ([] === $selection->files) {
            return GateResult::passedBecause($this->name(), 'no changed PHP files in scope');
        }

        return $this->check($workspace, $selection);
    }

    abstract protected function check(Workspace $workspace, PhpFileSelection $selection): GateResult;
}
