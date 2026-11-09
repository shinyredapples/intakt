<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Uri\Rfc3986\Uri;

// One open task stands for every red run on main, so a later red run rewrites that task instead of filing another.
final readonly class JourneyAlarm
{
    public function __construct(private GitHubIssues $issues)
    {
    }

    public function raise(string $output, ?Uri $link): string
    {
        $run = JourneyRun::fromOutput($output);

        if (!$run->isRed()) {
            return 'no journey failed: nothing filed';
        }

        $task = new JourneyAlarmTask($run, $link);
        $open = $this->issues->oldestOpenIssueHolding(JourneyAlarmTask::MARKER);

        if (null === $open) {
            return 'filed '.$this->issues->create($task->title(), $task->body(), JourneyAlarmTask::LABELS);
        }

        $this->issues->replaceBody($open, $task->body());

        return 'updated #'.$open;
    }
}
