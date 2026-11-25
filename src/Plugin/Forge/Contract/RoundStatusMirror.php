<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

// A label is a mirror of the round and never part of it, so a forge without labels or a write that fails costs the round nothing.
final readonly class RoundStatusMirror
{
    private const string LOCK_PREFIX = 'intakt-status-label-';

    public function __construct(
        private LatestRoundStatus $rounds,
        private LockFactory $locks,
        private LoggerInterface $logger,
        private ?StatusLabels $labels = null,
    ) {
    }

    // The one step every caller runs, and none passes a status: it comes from the stored round, so a late or repeated call cannot leave an older one.
    public function reconcile(string $project, ChangeId $change): void
    {
        if (!$this->labels instanceof StatusLabels) {
            return;
        }

        $lock = $this->lockOf($project, $change);
        // Tried without waiting, because the holder reads the stored round under the lock and every later pass reconciles again.
        if (!$lock->acquire(false)) {
            $this->logger->info('The status label of {change} of {project} was left to the writer holding its lock.', ['change' => $change->value, 'project' => $project]);

            return;
        }

        try {
            $this->labels->match($project, $change, $this->wantedOf($project, $change));
            // Boundary: logged and dropped, because the next pass reconciles the label again and a retry of the round must not wait on a label.
        } catch (Throwable $failure) {
            $this->logger->warning('The status label of {change} of {project} was not reconciled: {reason}', ['change' => $change->value, 'project' => $project, 'reason' => $failure->getMessage()]);
        } finally {
            $lock->release();
        }
    }

    // Keyed in lower case, because a forge such as GitHub reads owner and repository so and two spellings name one pull request.
    private function lockOf(string $project, ChangeId $change): LockInterface
    {
        return $this->locks->createLock(self::LOCK_PREFIX.hash('sha256', json_encode([mb_strtolower($project), mb_strtolower($change->value)], JSON_THROW_ON_ERROR)));
    }

    private function wantedOf(string $project, ChangeId $change): ?RoundStatusLabel
    {
        $status = $this->rounds->of($project, $change->value);

        return $status instanceof ReviewStatus ? RoundStatusLabel::of($status) : null;
    }
}
