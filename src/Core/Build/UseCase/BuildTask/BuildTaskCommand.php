<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:build:task', description: 'Read one issue of a project as the task, update the project\'s mirror, make the build clone, run the build job in it, check the bundle it hands back, run the pre-push check on it and push the branch signed, recording each step in the ledger')]
final readonly class BuildTaskCommand
{
    public function __construct(private BuildTask $buildTask)
    {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] BuildTaskInput $input): int
    {
        $request = $this->requestOf($input);

        if (is_string($request)) {
            $io->error($request);

            return Command::FAILURE;
        }

        try {
            $outcome = $this->buildTask->run($request);
        } catch (BuildStepNotRecorded|PushRemoteRefused $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->report($io, $outcome);

        return $outcome->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    private function requestOf(BuildTaskInput $input): BuildTaskRequest|string
    {
        if (1 !== preg_match('/^[1-9][0-9]{0,8}$/', $input->issue)) {
            return sprintf('"%s" is not an issue number', $input->issue);
        }

        foreach (['remote' => $input->remote, 'branch' => $input->branch, 'directory' => $input->directory, 'image' => $input->image] as $name => $value) {
            if (null === $value || '' === $value) {
                return sprintf('The --%s option is required.', $name);
            }
        }

        return new BuildTaskRequest($input->project, (int) $input->issue, (string) $input->remote, (string) $input->branch, (string) $input->directory, (string) $input->image);
    }

    private function report(SymfonyStyle $io, BuildTaskOutcome $outcome): void
    {
        foreach ($outcome->passedSteps() as $step) {
            $io->writeln($step->value.' passed');
        }

        if ($outcome->task instanceof ListedIssue) {
            $io->writeln('title '.$this->visible($outcome->task->title));
        }

        if ($outcome->failed()) {
            $io->error(sprintf('%s failed: %s', $outcome->last->value, $outcome->failure));
        }

        // Fixed words, never the question, because the builder wrote it.
        if ($outcome->parked()) {
            $io->warning(sprintf('%s asked: the task is parked until its question is answered', $outcome->last->value));
        }
    }

    private function visible(string $title): string
    {
        // Because the title is untrusted text, and a control character in it (ESC, BEL, CSI) would drive the operator's terminal; a title that is not UTF-8 keeps only its printable ASCII, since its other bytes cannot be told from control characters.
        $visible = preg_replace('/\p{Cc}/u', '?', $title) ?? preg_replace('/[^\x20-\x7E]/', '?', $title);

        // Because the title may hold "<info>", which the formatter would read as a tag.
        return OutputFormatter::escape((string) $visible);
    }
}
