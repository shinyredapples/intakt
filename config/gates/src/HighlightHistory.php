<?php

declare(strict_types=1);

namespace Intakt\Gates;

// A later change replaces an earlier one's row whatever their issue numbers, so the fragments need the order they reached the branch.
final readonly class HighlightHistory
{
    private const string COMMIT = '/^[0-9a-f]{40,64}$/';

    private const int UNBORN_HEAD = 1;

    public function __construct(
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    public function orderOn(string $root): HighlightOrder|string
    {
        // First-parent history only, because on the main branch a merge or squash commit adds a change's fragment when it is merged, and the order inside a merge is not reconstructed from its topology.
        $head = file_exists($root.'/.git') ? $this->runner->git(['rev-parse', '--verify', '--quiet', 'HEAD'], $root) : null;

        // With --quiet, exit status 1 is a branch without commits; any other failure is history git cannot read.
        if (!$head instanceof ProcessResult || self::UNBORN_HEAD === $head->exitCode) {
            return new HighlightOrder();
        }

        $unreadable = $this->unreadableHistoryFinding($root, $head);

        if (null !== $unreadable) {
            return $unreadable;
        }

        $log = $this->runner->git(['log', '--first-parent', '--diff-merges=first-parent', '--no-renames', '--reverse', '--diff-filter=A', '--name-only', '--format=%H', 'HEAD', '--', HighlightFragments::DIRECTORY.'/'], $root);

        return $log->succeeded() ? new HighlightOrder($this->placesIn($log->output)) : 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$log->firstErrorLine();
    }

    private function unreadableHistoryFinding(string $root, ProcessResult $head): ?string
    {
        if (!$head->succeeded()) {
            return 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$head->firstErrorLine();
        }

        return $this->shallowHistoryFinding($root);
    }

    /**
     * @return array<string, int>
     */
    private function placesIn(string $log): array
    {
        $places = [];
        $position = -1;

        // The log runs oldest first, so a fragment deleted and added again keeps the place of its last addition.
        foreach (explode("\n", $log) as $line) {
            if (1 === preg_match(self::COMMIT, rtrim($line))) {
                ++$position;
            } elseif ('' !== $line && $position >= 0) {
                $places[basename($line)] = $position;
            }
        }

        return $places;
    }

    // A shallow clone hides the commits that added the older fragments, which would quietly reorder them.
    private function shallowHistoryFinding(string $root): ?string
    {
        $shallow = $this->runner->git(['rev-parse', '--is-shallow-repository'], $root);

        if (!$shallow->succeeded()) {
            return 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$shallow->firstErrorLine();
        }

        return 'false' === trim($shallow->output) ? null : 'the history of '.HighlightFragments::DIRECTORY.'/ is shallow, so the order its fragments were merged in is unknown: fetch the full history (git fetch --unshallow)';
    }
}
