<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogEvents;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;

// The tokens every earlier build job of a task used, from the build_job rows of its runs, so the per-task total of the next build counts across continuations.
final readonly class TaskTokens
{
    public function __construct(
        private Ledger $ledger,
    ) {
    }

    public function usedBy(string $project, int $task): int
    {
        $tokens = 0;

        foreach ($this->ledger->readOfKind(LedgerEventKind::BuildStepEnded) as $recorded) {
            $payload = PayloadFields::of($recorded);

            if ($project === $payload->text('project') && (string) $task === $payload->text('task') && BuildStep::BuildJob->value === $payload->text('step')) {
                $tokens = BuildLogEvents::plus($tokens, $payload->number('tokens') ?? 0);
            }
        }

        return $tokens;
    }
}
