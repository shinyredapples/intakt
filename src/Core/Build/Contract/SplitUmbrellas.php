<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// Answered from the recorded split plans, never from a label or the forge, because an umbrella is never built and plain code must decide that: docs/design/05-architecture/03-choosing-work.md
interface SplitUmbrellas
{
    public function of(string $project): UmbrellaTasks;
}
