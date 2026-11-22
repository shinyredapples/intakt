<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ShowBuildTier;

use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\Setback;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeEscalations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:build:tier', description: 'Print the tier the routing rules choose for described build facts, without calling a model')]
final readonly class ShowBuildTierCommand
{
    public function __construct(
        private BuildRouting $routing,
        private ChangeEscalations $escalations,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ShowBuildTierInput $input): int
    {
        $setback = null === $input->setback ? null : Setback::tryFrom($input->setback);

        if (null !== $input->setback && !$setback instanceof Setback) {
            $io->error(sprintf('Unknown setback "%s": use one of %s.', $input->setback, implode(', ', array_column(Setback::cases(), 'value'))));

            return Command::FAILURE;
        }

        try {
            $choice = $this->routing->tierFor($input->project, BuildFacts::fromLabels($input->label, $input->path, $setback)->afterEscalation(null === $input->change ? null : $this->escalations->outcomeAtLatestHead($input->project, $input->change)));
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf('tier %s, reason %s', $choice->tier->value, $choice->reason->value));

        return Command::SUCCESS;
    }
}
