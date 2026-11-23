<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\AdmitHeads;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\HeadChecks;
use JsonException;
use LogicException;

// Plays recorded GitHub responses, because a live forge is out of reach; it keeps the runs of the asked sha like the real call must.
/**
 * @phpstan-type Recorded array{name?: string, app?: array{slug: string}, event?: string, pull_requests: list<array{number: int}>, created_at: string, head_sha: string, status: string, conclusion: string|null}
 */
final class RecordedChecks implements HeadChecks
{
    /** @var list<string> */
    public array $asked = [];
    public ?DateTimeImmutable $pausedUntil = null;
    // Null pauses every head; a hash pauses that head only, so one tick can hold a paused head beside a readable one.
    public ?string $pausedHead = null;

    /**
     * @param array<string, string> $recordings
     */
    public function __construct(private readonly array $recordings)
    {
    }

    public function ciStateOf(string $project, CommitId $head): CiState
    {
        // Before the head is counted as asked, because the real forge refuses a read during its pause without any request.
        if ($this->pausedUntil instanceof DateTimeImmutable && in_array($this->pausedHead, [null, $head->hash], true)) {
            throw new ForgeRateLimited($this->pausedUntil);
        }

        $this->asked[] = $head->hash;
        $recorded = $this->read($this->recordings[$head->hash] ?? '');
        $runs = [];

        foreach (['workflow_runs', 'check_suites'] as $kind) {
            foreach ($recorded[$kind] as $entry) {
                if ($entry['head_sha'] === $head->hash) {
                    $runs[] = new CiRun($this->originOf($entry), $this->stateOf($entry['status'], $entry['conclusion']), $this->momentOf($entry['created_at']));
                }
            }
        }

        return new CiState($head, $runs);
    }

    /**
     * @return array<string, list<Recorded>>
     */
    private function read(string $file): array
    {
        $path = __DIR__.'/recorded/'.$file.'.json';

        if (!is_file($path)) {
            throw new LogicException('Not a recording: '.$file);
        }

        try {
            /** @var array<string, list<Recorded>> $recorded */
            $recorded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Not a recording: '.$file, 0, $e);
        }

        return $recorded;
    }

    // A check suite names no event, so its kind stands in for one.
    /**
     * @param Recorded $entry
     */
    private function originOf(array $entry): CiRunOrigin
    {
        $change = array_first($entry['pull_requests']);

        return new CiRunOrigin(
            $entry['name'] ?? $entry['app']['slug'] ?? 'check',
            $entry['event'] ?? 'check_suite',
            null === $change ? null : new ChangeId((string) $change['number']),
        );
    }

    private function stateOf(string $status, ?string $conclusion): CiRunState
    {
        return match (true) {
            'completed' !== $status => CiRunState::Unfinished,
            in_array($conclusion, ['success', 'neutral', 'skipped'], true) => CiRunState::Succeeded,
            'cancelled' === $conclusion => CiRunState::Cancelled,
            default => CiRunState::Failed,
        };
    }

    private function momentOf(string $recorded): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(DATE_ATOM, $recorded, new DateTimeZone('UTC'));

        return false === $moment ? throw new LogicException('Not a recorded moment: '.$recorded) : $moment;
    }
}
