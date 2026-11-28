<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:build:size', description: 'Check one new issue for readiness and estimate its size in one tool-less call, record the verdict in the ledger, park the task with one question when it is not ready and split it into its parts once when it must be split')]
final readonly class SizeTaskCommand
{
    public function __construct(private SizeTask $sizeTask)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The project name')]
        string $project,
        #[Argument('The number of the issue to size')]
        string $issue,
    ): int {
        if (1 !== preg_match('/^[1-9][0-9]{0,8}$/', $issue)) {
            $io->error(sprintf('"%s" is not an issue number', $issue));

            return Command::FAILURE;
        }

        try {
            $outcome = $this->sizeTask->run($project, (int) $issue);
        } catch (TaskNotSized $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        } catch (UsageNotRecorded|ProviderRateLimited|ProviderFailedTransiently|VendorAccountOut|VendorAccountBusy|NoProviderLeft|ProviderRejectedTheKey|ProviderUnreachable $failure) {
            $io->error('The sizing call failed: '.$failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('outcome '.$outcome->value);

        return Command::SUCCESS;
    }
}
