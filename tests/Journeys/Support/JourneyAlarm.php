<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Symfony\Component\Lock\LockFactory;
use Uri\Rfc3986\Uri;

// One open task stands for every red run on main, so a later red run rewrites that task instead of filing another.
final readonly class JourneyAlarm
{
    private const string LOCK = 'intakt-journey-alarm';

    public function __construct(
        private GitHubIssues $issues,
        private LockFactory $locks,
    ) {
    }

    public function raise(string $output, ?Uri $link, bool $timedOut = false): string
    {
        $run = $timedOut ? JourneyRun::timedOut($output) : JourneyRun::fromOutput($output);

        if (!$run->isRed()) {
            return 'no journey failed: nothing filed';
        }

        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);

        try {
            return $this->fileOrUpdate(new JourneyAlarmTask($run, $link));
        } finally {
            $lock->release();
        }
    }

    private function fileOrUpdate(JourneyAlarmTask $task): string
    {
        $open = $this->issues->oldestOpenIssueHolding(JourneyAlarmTask::MARKER);

        if (null !== $open) {
            $this->issues->replaceBody($open, $task->body());

            return 'updated #'.$open;
        }

        $created = $this->issues->create($task->title(), $task->body(), JourneyAlarmTask::LABELS);
        // The lock does not reach a run on another machine, so one that filed meanwhile is found here and its older task is kept.
        $oldest = $this->issues->oldestOpenIssueHolding(JourneyAlarmTask::MARKER);

        if (null === $oldest || $oldest >= $created['number']) {
            return 'filed '.$created['link'];
        }

        $this->issues->closeAsDuplicateOf($created['number'], $oldest);
        $this->issues->replaceBody($oldest, $task->body());

        return 'updated #'.$oldest.', closed #'.$created['number'].' as its duplicate';
    }
}
