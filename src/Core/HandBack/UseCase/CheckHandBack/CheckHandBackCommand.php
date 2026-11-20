<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:hand-back:check', description: 'Check the bundle and pull request text a build returned, strip attribution from its commits, and send a failure back once before handing the task over')]
final readonly class CheckHandBackCommand
{
    public function __construct(
        private RouteHandBack $routes,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] CheckHandBackInput $input): int
    {
        try {
            $credentials = new JobCredentials(...array_map($this->credentialIn(...), $input->credentialEnv));
            $text = $this->pullRequestTextIn($input->pullRequestText);
            $result = $this->routes->route(new HandBack($input->repository, $input->branch, $input->bundle, $input->mergeMain, $credentials), $text);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        if ($result instanceof FailedHandBack) {
            $io->error(sprintf('refused, %s: %s', $this->routeInWords($result->route), $result->reason()));

            return Command::FAILURE;
        }

        $io->writeln('accepted '.$result->head);
        $io->writeln('base '.($result->base ?? 'none'));
        $io->writeln('rewritten '.$result->rewrittenCommits);

        return Command::SUCCESS;
    }

    // From the environment and never an argument, because the process list shows every argument; a credential without a value fails, because nothing would be redacted.
    private function credentialIn(string $variable): string
    {
        $value = $_SERVER[$variable] ?? getenv($variable);

        if (!is_string($value) || '' === $value) {
            throw CredentialVariableUnset::named($variable);
        }

        return $value;
    }

    private function pullRequestTextIn(string $path): string
    {
        try {
            return $this->filesystem->readFile($path);
        } catch (IOException $unreadable) {
            throw PullRequestTextUnreadable::at($path, $unreadable);
        }
    }

    private function routeInWords(HandBackRoute $route): string
    {
        return match ($route) {
            HandBackRoute::SentBack => 'sent back to the builder',
            HandBackRoute::HandedOver => 'handed over',
        };
    }
}
