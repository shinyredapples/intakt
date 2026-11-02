<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// Removes the snapshot startOf() kept for a round once the round is judged, because nothing reads it after the judge and every start keeps one.
#[AsEventListener]
final readonly class RemoveKeptSnapshot
{
    private const int TIMEOUT_SECONDS = 60;

    public function __construct(
        private LoggerInterface $logger,
        private HardenedGit $hardened = new HardenedGit(),
    ) {
    }

    public function __invoke(RoundJudged $judged): void
    {
        $snapshot = $judged->checkoutRoot;

        if (!$this->isKept($snapshot) || !is_dir($snapshot)) {
            return;
        }

        $failure = $this->failureOfRemoving($snapshot);

        if (null !== $failure) {
            $this->logger->warning('The snapshot of a judged round could not be removed; delete it and run "git worktree prune" in its repository.', ['round' => $judged->roundId, 'snapshot' => $snapshot, 'failure' => $failure]);
        }
    }

    // A direct child of the temp directory with the kept prefix only, because a round may review any checkout, and a person's own must never be removed.
    private function isKept(string $snapshot): bool
    {
        $name = '/^'.preg_quote(LocalGitForge::KEPT_SNAPSHOT_PREFIX, '/').'[0-9a-f]{16}$/';

        return dirname($snapshot) === realpath(sys_get_temp_dir()) && 1 === preg_match($name, basename($snapshot));
    }

    // Run inside the snapshot, because git finds its repository from there, and the event does not name the repository; a failure is returned, never thrown, because the round is judged already.
    private function failureOfRemoving(string $snapshot): ?string
    {
        try {
            $call = $this->hardened->call(['worktree', 'remove', '--force', $snapshot], $snapshot);
            $process = new Process($call->command, $call->directory, $call->environment, timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (RepositoryAttributesRefused|ExceptionInterface $failure) {
            return $failure->getMessage();
        }

        return $process->isSuccessful() ? null : trim($process->getErrorOutput());
    }
}
