<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:change:summarize', description: 'Print the change summary of a branch of a local repository against its base branch')]
final readonly class SummarizeChangeCommand
{
    public function __construct(private NameStatusParser $nameStatus)
    {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] SummarizeChangeInput $input): int
    {
        try {
            $change = new LocalGitForge(new LocalRepository($input->repository, $input->base), $this->nameStatus)->change(new ChangeId($input->branch));
        } catch (ChangeNotFound|ForgeReadFailed|InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->print($io, $change, $input->base);

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, Change $change, string $base): void
    {
        // Branch names and paths come from the repository and may contain "<info>", which the formatter would swallow.
        // Branch names need no control-character handling, because git refuses control characters in ref names.
        $io->writeln(OutputFormatter::escape(sprintf('%s against %s', $change->id->value, $base)));
        $io->writeln(sprintf('base %s, head %s', substr($change->commits->base->hash, 0, 12), substr($change->commits->head->hash, 0, 12)));
        $io->writeln(sprintf('%s, +%d -%d lines', $this->countFiles($change), $this->countLines($change, '+'), $this->countLines($change, '-')));

        foreach ($change->diff->files as $file) {
            $io->writeln($this->describe($file) |> OutputFormatter::escape(...));
        }
    }

    private function countFiles(Change $change): string
    {
        $count = count($change->diff->files);

        return 1 === $count ? '1 file changed' : sprintf('%d files changed', $count);
    }

    private function describe(ChangedFile $file): string
    {
        return sprintf('%-9s %s', $file->status->value, $this->visible($file->path));
    }

    private function visible(string $path): string
    {
        // A path may hold ESC or other control bytes that would rewrite the terminal; the backslash is escaped so the visible form stays unambiguous.
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F\\\\]|\xC2[\x80-\x9F]/',
            static fn (array $match): string => '\\' === $match[0] ? '\\\\' : implode('', array_map(static fn (string $byte): string => sprintf('\x%02x', ord($byte[0])), str_split($match[0]))),
            $path,
        );
    }

    private function countLines(Change $change, string $marker): int
    {
        // Only lines inside a hunk are counted, because the "+++" and "---" file headers look like a changed line "++x" or "--x".
        $count = 0;
        $inHunk = false;

        foreach (explode("\n", $change->diff->patch) as $line) {
            $inHunk = $this->isInHunk($line, $inHunk);
            $count += $inHunk && str_starts_with($line, $marker) ? 1 : 0;
        }

        return $count;
    }

    private function isInHunk(string $line, bool $wasInHunk): bool
    {
        return match (true) {
            str_starts_with($line, 'diff ') => false,
            str_starts_with($line, '@@') => true,
            default => $wasInHunk,
        };
    }
}
