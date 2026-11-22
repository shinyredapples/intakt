<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\CheckVerdictIdentity;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\RecordedIdentityMalformed;
use Intakt\Core\Review\Contract\ReviewTermMalformed;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:verdict:identity', description: 'Print the verdict identity of a branch of a local repository, and whether a recorded one still counts')]
final readonly class CheckVerdictIdentityCommand
{
    public function __construct(
        private Panels $panels,
        private NameStatusParser $nameStatus,
        private VisiblePath $paths = new VisiblePath(),
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] CheckVerdictIdentityInput $input): int
    {
        try {
            $this->check($io, $input);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function check(SymfonyStyle $io, CheckVerdictIdentityInput $input): void
    {
        $forge = new LocalGitForge(LocalRepository::existingAt($input->repository, $input->base), $this->nameStatus);
        $change = $forge->change(new ChangeId($input->branch));
        $current = new VerdictIdentity($change->commits, $this->termsOf($change, $input));
        $io->writeln('identity '.$current->recorded());

        if (null === $input->reviewed) {
            return;
        }

        $reviewed = $this->readReviewed($input->reviewed);
        $this->printStanding($io, $reviewed->standingAt($current, $forge->headMoveSince($reviewed->commits->head, $change)));
    }

    // Reportable here only, because here a person typed the identity, while an unreadable one from storage is a programming error.
    private function readReviewed(string $recorded): VerdictIdentity
    {
        try {
            return VerdictIdentity::fromRecorded($recorded);
        } catch (RecordedIdentityMalformed|ReviewTermMalformed|CommitIdMalformed $invalid) {
            throw new RecordedIdentityUnreadable('--reviewed is not a recorded verdict identity: '.$invalid->getMessage(), $invalid->getCode(), $invalid);
        }
    }

    private function termsOf(Change $change, CheckVerdictIdentityInput $input): ReviewTerms
    {
        $paths = array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]);
        $description = new ChangeDescription($change->diff->addedLines() + $change->diff->removedLines(), new Paths(...$paths), new Labels());

        return ReviewTerms::of($input->task, $input->checkCommand, $this->panels->panelForChange($input->project, $description));
    }

    private function printStanding(SymfonyStyle $io, VerdictStanding $standing): void
    {
        $changed = implode(', ', array_map(static fn (IdentityField $field): string => $field->value, $standing->changed));
        $io->writeln('' === $changed ? $standing->standing->value : sprintf('%s: %s changed', $standing->standing->value, $changed));

        foreach ($standing->reviewAgain as $path) {
            $io->writeln('review again '.$this->paths->of($path));
        }
    }
}
