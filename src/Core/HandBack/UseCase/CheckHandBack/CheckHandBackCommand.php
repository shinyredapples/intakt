<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:hand-back:check', description: 'Check the bundle a build returned against main and the task\'s branch, and strip attribution from its commits')]
final readonly class CheckHandBackCommand
{
    public function __construct(
        private CheckHandBack $checks,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] CheckHandBackInput $input): int
    {
        try {
            $result = $this->checks->check(new HandBack($input->repository, $input->branch, $input->bundle, $input->mergeMain));
        } catch (InvalidArgumentException|ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        if ($result instanceof RefusedHandBack) {
            $io->error('refused: '.$result->reason());

            return Command::FAILURE;
        }

        $io->writeln('accepted '.$result->head);
        $io->writeln('base '.($result->base ?? 'none'));
        $io->writeln('rewritten '.$result->rewrittenCommits);

        return Command::SUCCESS;
    }
}
