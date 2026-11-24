<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Symfony\Component\Lock\LockFactory;
use Uri\Rfc3986\Uri;

// One open task stands for each failing step because a marker made of the step's identity finds it again; the next pass that finds the step green closes it.
final readonly class InstallCheckAlarm
{
    private const string LOCK = 'intakt-journey-alarm';
    private const string MARKER = '<!-- intakt:install-check:';
    private const string INSTALL = 'install:';

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
            $lines = [];
            $markers = [];
            $installs = 0;
            // Only issues the alarm's own account opened are its to edit or close, because anyone can copy a marker into an issue.
            $account = $this->issues->account();

            foreach ($report->steps as $step) {
                // An install step is known by its place in the README block, so a fixed command closes the task its broken spelling filed.
                $identity = str_starts_with($step->name, self::INSTALL) ? self::INSTALL.' #'.++$installs : $step->name;
                $markers[] = $this->markerOf($identity);
                $lines[] = $this->settle($step, array_last($markers), $link, $account);
            }

            $installed = $installs > 0 && !array_any($report->steps, static fn (InstallStepResult $step): bool => str_starts_with($step->name, self::INSTALL) && InstallStatus::Failed === $step->status);
            $lines = array_values(array_filter([...$lines, ...($installed ? $this->closeMissing($markers, $account) : [])], static fn (?string $line): bool => null !== $line));

            return [] === $lines ? 'no install step failed: nothing filed' : implode("\n", $lines);
        } finally {
            $lock->release();
        }
    }

    // The identity is hashed, so no step name can break the marker line.
    private function markerOf(string $identity): string
    {
        return self::MARKER.hash('sha256', $identity).' -->';
    }

    private function settle(InstallStepResult $step, string $marker, ?Uri $link, string $account): ?string
    {
        $open = $this->issues->oldestOpenIssueHolding($marker, $account);

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

        $created = $this->issues->create('fix(install): install check step "'.mb_strimwidth($step->name, 0, 60, '...').'" fails', $body, JourneyAlarmTask::LABELS);
        // The lock holds only on one machine, so a run elsewhere may have filed the same task meanwhile: the oldest stays.
        $oldest = $this->issues->oldestOpenIssueHolding($marker, $account);

        if (null === $oldest || $oldest >= $created['number']) {
            return 'filed '.$created['link'];
        }

        $this->issues->closeAsDuplicateOf($created['number'], $oldest);
        $this->issues->replaceBody($oldest, $body);

        return 'updated #'.$oldest.', closed #'.$created['number'].' as its duplicate';
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

    // A README that lost a command shifts or drops the places after it, so once every install command passes, a task for a step this run did not have is closed.
    /**
     * @param list<string> $markers
     *
     * @return list<string>
     */
    private function closeMissing(array $markers, string $account): array
    {
        $lines = [];

        foreach ($this->issues->openIssuesOpeningWith('/\A'.preg_quote(self::MARKER, '/').'[0-9a-f]{64} -->\z/', $account) as $number => $marker) {
            if (!in_array($marker, $markers, true)) {
                $this->issues->close($number, 'The install check passes and no longer has this step.');
                $lines[] = 'closed #'.$number.' (a step this run no longer has)';
            }
        }

        return $lines;
    }
}
