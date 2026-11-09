<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// Implemented by the raw result of a subscription CLI call that reached the per-review server, because the CLI read those answers itself and Intakt's toolbox never saw them.
interface ReviewServerAnswers
{
    // A call the CLI reported no answer for gives an empty text, so it can never pass as a whole read.
    /**
     * @return list<string>
     */
    public function reviewServerAnswers(): array;

    // Called by whoever started the call once the review is over, also when it failed or never read the result, because the call holds the round's credential on disk.
    public function forgetTheCredential(): void;
}
