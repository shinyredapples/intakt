<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\HeadChecks;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\Forge\Contract\OpenChanges;
use LogicException;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Serves recorded pull requests, their CI runs and their authors' trust to an install in the test environment, so the journeys and the evidence of a tick need no GitHub; GitHubWatchReads and GitHubAuthorTrust serve an installed instance.
/**
 * @phpstan-type Pull array{number: int, head: array{sha: string}, updated_at: string, author_trusted?: bool}
 * @phpstan-type Run array{name: string, event: string, head_sha: string, created_at: string, status: string, conclusion: string|null}
 */
final readonly class RecordedPullRequests implements OpenChanges, HeadChecks, AuthorTrust
{
    public function __construct(
        #[Autowire('%env(WATCHER_RECORDING)%')]
        private string $recording,
    ) {
    }

    public function listOpen(string $project, ?string $validator): OpenChangeListing
    {
        $current = 'W/"'.hash_file('sha256', $this->file()).'"';

        if ($current === $validator) {
            return OpenChangeListing::unchanged();
        }

        $open = array_map(fn (array $pull): OpenChange => new OpenChange(new ChangeId((string) $pull['number']), new CommitId($pull['head']['sha']), $this->momentOf($pull['updated_at'])), $this->read()['pulls']);

        return OpenChangeListing::listed($open, $current);
    }

    public function ciStateOf(string $project, CommitId $head): CiState
    {
        $runs = array_filter($this->read()['workflow_runs'], static fn (array $run): bool => $run['head_sha'] === $head->hash);

        return new CiState($head, array_values(array_map(fn (array $run): CiRun => new CiRun(new CiRunOrigin($run['name'], $run['event'], null), $this->stateOf($run), $this->momentOf($run['created_at'])), $runs)));
    }

    // Untrusted unless the recording says so, because the real check answers false when it cannot answer, and a recording without the key must boot nothing.
    #[NoDiscard('an author nobody looks at may run code on the host')]
    public function isAuthorTrusted(ChangeId $change): bool
    {
        $pull = array_first(array_filter($this->read()['pulls'], static fn (array $pull): bool => (string) $pull['number'] === $change->value));

        return true === ($pull['author_trusted'] ?? false);
    }

    /**
     * @param Run $run
     */
    private function stateOf(array $run): CiRunState
    {
        return match (true) {
            'completed' !== $run['status'] => CiRunState::Unfinished,
            'success' === $run['conclusion'] => CiRunState::Succeeded,
            default => CiRunState::Failed,
        };
    }

    private function file(): string
    {
        return is_file($this->recording) ? $this->recording : throw new LogicException('WATCHER_RECORDING names no recording file.');
    }

    private function momentOf(string $recorded): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $recorded, new DateTimeZone('UTC'));

        return false === $moment ? throw new LogicException('Not a recorded moment: '.$recorded) : $moment;
    }

    /**
     * @return array{pulls: list<Pull>, workflow_runs: list<Run>}
     */
    private function read(): array
    {
        /** @var array{pulls: list<Pull>, workflow_runs: list<Run>} $recorded */
        $recorded = json_decode((string) file_get_contents($this->file()), true, flags: JSON_THROW_ON_ERROR);

        return $recorded;
    }
}
