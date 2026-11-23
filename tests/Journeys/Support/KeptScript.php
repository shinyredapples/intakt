<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Platform\Contract\KeptForTheProcess;

// The script a worker read first, shared by its HTTP client and every client derived from it with other options, so all of them answer from that one script for the whole process.
final class KeptScript
{
    // Kept for the process, because a worker must answer all its calls from the script it read first, also when the journey arms it again in the middle of the run.
    /**
     * @var array{weight: float, answers: non-empty-list<array{content_type: string, body: string}>}|null
     */
    #[KeptForTheProcess]
    private ?array $script = null;

    /**
     * @return array{weight: float, answers: non-empty-list<array{content_type: string, body: string}>}
     *
     * @throws ScriptNotArmed
     */
    public function readFrom(ScriptDirectory $scripts): array
    {
        return $this->script ??= $scripts->script();
    }
}
