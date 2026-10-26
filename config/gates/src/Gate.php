<?php

declare(strict_types=1);

namespace Intakt\Gates;

interface Gate
{
    public function name(): string;

    public function run(Workspace $workspace): GateResult;
}
