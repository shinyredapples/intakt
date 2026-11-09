<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// Implemented by the raw result of a subscription CLI call that reached the per-review server, because the CLI read those answers itself and Intakt's toolbox never saw them.
interface ReviewServerAnswers
{
    // One text per call of a server tool, in the CLI's order; an empty one when the CLI reported no answer for a call.
    /**
     * @return list<string>
     */
    public function reviewServerAnswers(): array;
}
