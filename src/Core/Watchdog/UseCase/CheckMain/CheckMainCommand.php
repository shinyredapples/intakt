<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\OwnerKeys;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:watchdog:check', description: 'Classify the new commits on main: a recorded merge or an owner-signed commit passes, anything else is a violation')]
final readonly class CheckMainCommand
{
    public function __construct(
        private CheckMain $check,
        private Mirrors $mirrors,
    ) {
    }

    /**
     * @param list<string> $ownerKey
     * @param list<string> $recordedMerge
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The project name, whose bare mirror holds main')]
        string $project,
        #[Option(description: 'The head of main the last check passed; omitted, all of main is checked')]
        ?string $since = null,
        #[Option(description: 'A public key file of the owner (OpenSSH format, one key per line); repeatable')]
        array $ownerKey = [],
        #[Option(description: 'A merge commit Intakt recorded; repeatable')]
        array $recordedMerge = [],
    ): int {
        try {
            $main = new WatchedMain($since, $recordedMerge, OwnerKeys::of($this->keysIn($ownerKey)));
            $verdict = $this->check->of($this->mirrors->pathOf($project), $main);
        } catch (InvalidArgumentException|MainNotRead $refused) {
            $io->error($refused->getMessage());

            return Command::INVALID;
        }

        foreach ($verdict->violations as $violation) {
            $io->writeln($violation->kind->value.' '.$violation->commit);
        }
        $io->writeln('head '.$verdict->head);
        $io->writeln('main is '.($verdict->isClean() ? 'clean' : 'violated'));

        return $verdict->isClean() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function keysIn(array $files): array
    {
        $keys = [];
        foreach ($files as $file) {
            $content = is_file($file) ? file_get_contents($file) : false;

            if (false === $content) {
                throw new InvalidArgumentException(sprintf('the owner key file %s cannot be read', $file));
            }

            $keys = [...$keys, ...array_filter(array_map(trim(...), explode("\n", $content)), static fn (string $line): bool => '' !== $line)];
        }

        return $keys;
    }
}
