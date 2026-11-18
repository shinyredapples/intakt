<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ChoosePanel;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:review:panel', description: 'Print the review panel the configuration chooses for a described change, without calling a model')]
final readonly class ChoosePanelCommand
{
    public function __construct(private Panels $panels)
    {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ChoosePanelInput $input): int
    {
        if ($input->lines < 0) {
            $io->error('--lines must be zero or more.');

            return Command::FAILURE;
        }

        $change = new ChangeDescription($input->lines, new Paths(...$input->path), new Labels(...$input->label));

        try {
            $panel = $this->panels->panelForChange($input->project, $change);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->print($io, $panel);

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, Panel $panel): void
    {
        $io->writeln(sprintf('quorum %s, %d reviewers', $panel->quorum->value, count($panel->reviewers)));

        foreach ($panel->reviewers as $reviewer) {
            $io->writeln(OutputFormatter::escape($this->describe($reviewer)));
        }
    }

    private function describe(PanelReviewer $reviewer): string
    {
        return sprintf('%s: tier %s, provider %s, budget %d', $reviewer->role->name, $reviewer->model->tier->value, $reviewer->model->provider->value, $reviewer->budget);
    }
}
