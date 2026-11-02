<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\AdmitHeads;

use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\HeadChecks;
use JsonException;
use LogicException;

// Answers from recorded GitHub responses (workflow runs and check suites of a repository), keeping only the runs of the asked sha.
final class RecordedChecks implements HeadChecks
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, string> $recordings recorded file name by head sha
     */
    public function __construct(private readonly array $recordings)
    {
    }

    public function ciStateOf(string $project, CommitId $head): CiState
    {
        $this->asked[] = $head->hash;
        $recorded = $this->read($this->recordings[$head->hash] ?? '');
        $runs = [];

        foreach (['workflow_runs', 'check_suites'] as $kind) {
            foreach ($recorded[$kind] as $entry) {
                if ($entry['head_sha'] === $head->hash) {
                    $runs[] = new CiRun($entry['name'] ?? $entry['app'], $this->stateOf($entry['status'], $entry['conclusion']));
                }
            }
        }

        return new CiState($head, $runs);
    }

    private function stateOf(string $status, ?string $conclusion): CiRunState
    {
        return match (true) {
            'completed' !== $status => CiRunState::Unfinished,
            in_array($conclusion, ['success', 'neutral', 'skipped'], true) => CiRunState::Succeeded,
            default => CiRunState::Failed,
        };
    }

    /**
     * @return array<string, list<array<string, string|null>>>
     */
    private function read(string $file): array
    {
        try {
            $recorded = json_decode((string) file_get_contents(__DIR__.'/recorded/'.$file.'.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Not a recording: '.$file, 0, $e);
        }

        return $recorded;
    }
}
