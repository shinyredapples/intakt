<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ChooseBuildModel;

use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\Setback;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:build:model', description: 'Choose the model of a build by the routing rules and record it as a model event, without calling a model')]
final readonly class ChooseBuildModelCommand
{
    public function __construct(private ChooseBuildModel $chooseBuildModel)
    {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ChooseBuildModelInput $input): int
    {
        $setback = null === $input->setback ? null : Setback::tryFrom($input->setback);

        if (null !== $input->setback && !$setback instanceof Setback) {
            $io->error(sprintf('Unknown setback "%s": use one of %s.', $input->setback, implode(', ', array_column(Setback::cases(), 'value'))));

            return Command::FAILURE;
        }

        $refusal = BuildAttempt::refusalOf($input->project, $input->task, $input->attempt);

        if (null !== $refusal) {
            $io->error($refusal);

            return Command::FAILURE;
        }

        try {
            $chosen = $this->chooseBuildModel->chooseFor(
                new BuildAttempt($input->project, $input->task, $input->attempt),
                BuildFacts::fromLabels(new Labels(...$input->label), new Paths(...$input->path), $setback),
            );
        } catch (BuildRoutingNotConfigured|TierResolutionFailure|BuilderNotOnClaudeCode|ModelChoiceNotRecorded $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf('tier %s, reason %s, provider %s, model %s', $chosen->choice->tier->value, $chosen->choice->reason->value, $chosen->model->provider->value, $chosen->model->model));

        return Command::SUCCESS;
    }
}
