<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ChooseBuildModel;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRuntime;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use NoDiscard;

// The facts come from the loop, never from the builder, so a job cannot pick its own model.
final readonly class ChooseBuildModel
{
    public function __construct(
        private BuildRouting $routing,
        private TierResolver $tiers,
        private Ledger $ledger,
    ) {
    }

    /**
     * @throws BuilderNotOnClaudeCode
     * @throws ModelChoiceNotRecorded
     */
    #[NoDiscard('the build runs on the model it returns')]
    public function chooseFor(BuildAttempt $attempt, BuildFacts $facts): ChosenModel
    {
        $choice = $this->routing->tierFor($attempt->project, $facts);
        $chosen = new ChosenModel($choice, $this->buildModelFor($attempt->project, $choice->tier));

        $this->record($attempt, $chosen);

        return $chosen;
    }

    // A build runs the Claude Code CLI, so the ledger must never name a model no build can run on.
    private function buildModelFor(string $project, Tier $tier): ResolvedModel
    {
        $resolved = $this->tiers->resolve($project, $tier);

        if (BuildRuntime::PROVIDER !== $resolved->provider) {
            throw new BuilderNotOnClaudeCode($project, $tier, $resolved->provider);
        }

        return $resolved;
    }

    private function record(BuildAttempt $attempt, ChosenModel $chosen): void
    {
        $payload = json_encode([
            'project' => $attempt->project,
            'task' => $attempt->task,
            'attempt' => $attempt->number,
            'tier' => $chosen->choice->tier->value,
            'reason' => $chosen->choice->reason->value,
            'provider' => $chosen->model->provider->value,
            'model' => $chosen->model->model,
        ], JSON_THROW_ON_ERROR);

        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::ModelChosen, $payload));

        if (!$result->succeeded()) {
            throw ModelChoiceNotRecorded::for($attempt, (string) $result->failure);
        }
    }
}
