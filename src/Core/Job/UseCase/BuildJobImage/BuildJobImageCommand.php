<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:job:build-image', description: 'Build the job image of a project checkout from its devcontainer, its Dockerfile or composer.json, and check its PHP')]
final readonly class BuildJobImageCommand
{
    public function __construct(private BuildJobImage $images)
    {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] BuildJobImageInput $input): int
    {
        $tag = $input->tag;

        try {
            $built = $this->images->build($input->directory, $tag, $input->kind);
        } catch (ReportableFailure|JobRefused $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($built->plan->summary());
        $io->write($built->output);
        $io->success(null === $built->phpVersion ? sprintf('Image %s (%s) built.', $tag, $built->image) : sprintf('Image %s (%s) built: PHP %s with every required extension.', $tag, $built->image, $built->phpVersion));

        return Command::SUCCESS;
    }
}
