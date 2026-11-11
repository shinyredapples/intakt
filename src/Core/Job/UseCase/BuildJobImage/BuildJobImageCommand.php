<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:job:image', description: 'Build the job image of a project checkout from its devcontainer, its Dockerfile or composer.json')]
final readonly class BuildJobImageCommand
{
    public function __construct(private BuildJobImage $images)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The project checkout; the files of its HEAD commit decide the image')]
        string $directory,
        #[Argument('The tag the image gets, for example intakt-job/demo:main')]
        string $tag,
        #[Option('Print where the image comes from and the generated Dockerfile, without building')]
        bool $dryRun = false,
    ): int {
        try {
            $dryRun ? $this->printPlan($io, $directory) : $this->build($io, $directory, $tag);
        } catch (ReportableFailure|JobRefused $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function printPlan(SymfonyStyle $io, string $directory): void
    {
        $plan = $this->images->plan($directory);
        $io->writeln($this->describe($plan));
        $io->writeln($plan->generated ?? sprintf('Builds %s in %s.', $plan->dockerfile, '' === $plan->tree ? 'the repository root' : $plan->tree));
    }

    private function build(SymfonyStyle $io, string $directory, string $tag): void
    {
        $built = $this->images->build($directory, $tag);
        $io->writeln($this->describe($built->plan));
        $io->write($built->output);
        $io->success(null === $built->phpVersion ? sprintf('Image %s built.', $tag) : sprintf('Image %s built: PHP %s with every required extension.', $tag, $built->phpVersion));
    }

    private function describe(JobImagePlan $plan): string
    {
        $php = $plan->php;

        if (!$php instanceof PhpProfile) {
            return sprintf('Job image from %s, without composer.json to check its PHP against.', $plan->source->label());
        }

        $extensions = [] === $php->extensions ? '' : ' with '.implode(', ', $php->extensions);

        return sprintf('Job image from %s for PHP %s%s.', $plan->source->label(), $php->rule, $extensions);
    }
}
