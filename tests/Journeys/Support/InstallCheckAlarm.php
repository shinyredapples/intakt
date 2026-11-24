<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Symfony\Component\Lock\LockFactory;
use Uri\Rfc3986\Uri;

// One open task stands for each failing step because a marker made of the step's name finds it again; the next pass that finds the step green closes it.
final readonly class InstallCheckAlarm
{
    private const string LOCK = 'intakt-journey-alarm';
    private const string MARKER = '<!-- intakt:install-check:';

    public function __construct(
        private GitHubIssues $issues,
        private LockFactory $locks,
    ) {
    }

    public function raise(InstallReport $report, ?Uri $link): string
    {
        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);

        try {
            $lines = array_values(array_filter(array_map(fn (InstallStepResult $step): ?string => $this->settle($step, $link), $report->steps), static fn (?string $line): bool => null !== $line));

            return [] === $lines ? 'no install step failed: nothing filed' : implode("\n", $lines);
        } finally {
            $lock->release();
        }
    }

    private function settle(InstallStepResult $step, ?Uri $link): ?string
    {
        $marker = $this->markerOf($step);
        $open = $this->issues->oldestOpenIssueHolding($marker);

        if (InstallStatus::Passed === $step->status && null !== $open) {
            $this->issues->close($open, 'The install check passes this step again.');

            return 'closed #'.$open.' ('.$step->name.')';
        }

        if (InstallStatus::Failed !== $step->status) {
            return null;
        }

        $body = $this->body($step, $marker, $link);

        if (null !== $open) {
            $this->issues->replaceBody($open, $body);

            return 'updated #'.$open;
        }

        return 'filed '.$this->issues->create('fix(install): install check step "'.mb_strimwidth($step->name, 0, 60, '...').'" fails', $body, JourneyAlarmTask::LABELS)['link'];
    }

    // The name is hashed, so no step name can break the marker line.
    private function markerOf(InstallStepResult $step): string
    {
        return self::MARKER.hash('sha256', $step->name).' -->';
    }

    private function body(InstallStepResult $step, string $marker, ?Uri $link): string
    {
        // The end of the log is where the failure shows, and a room of 50000 characters keeps the body under GitHub's limit.
        $tail = mb_substr($step->tail, -50000);
        $fence = str_repeat('`', max(3, $this->longestBacktickRun($tail) + 1));
        $run = $link instanceof Uri ? "\nRun: ".$link->toString()."\n" : '';
        $body = $marker."\nThe install check, which installs the README's way on a clean machine, failed at the step `".str_replace('`', "'", $step->name)."`.\n".$run
            ."\nLast lines of its log:\n\n".$fence."\n".$tail."\n".$fence."\n";

        return mb_substr($body, 0, JourneyAlarmTask::BODY_LIMIT - 1);
    }

    private function longestBacktickRun(string $text): int
    {
        preg_match_all('/`+/', $text, $runs);

        return array_reduce($runs[0], static fn (int $longest, string $run): int => max($longest, strlen($run)), 0);
    }
}
