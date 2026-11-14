<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use NoDiscard;

// The whole change, never a file list, because an approved one-line workflow edit can run a script nobody was shown.
final readonly class PushRequest
{
    // GitHub refuses a longer comment body, and a change cut to fit would be approved unseen.
    public const int MAX_CHARACTERS = 65536;
    private const string MARKER = '<!-- intakt-push-request: %s -->';
    private const int SHORTEST_FENCE = 3;

    public function marker(string $commit): string
    {
        return sprintf(self::MARKER, $commit);
    }

    /**
     * @throws ChangeNotShown
     */
    #[NoDiscard]
    public function for(HeldChange $change, CiScanResult $scan): string
    {
        $this->refuseUnshowable($change->diff);
        $body = implode("\n", [
            '**This change stays local until the owner approves its push.** It changes the CI definition, and the workflow scan finds it outside a forge-hosted runner with a read-only token and no secrets, so pushing it would run it on the project\'s runners unreviewed.',
            '',
            'To let exactly this head push, the owner\'s account comments only:',
            '',
            '    approve '.$change->commit,
            '',
            'Approving the push does not approve the merge: the owner is asked again before the change merges.',
            '',
            'What the scan found:',
            '',
            $this->fenced('text', $this->scanLines($scan)),
            '',
            'The complete change:',
            '',
            $this->fenced('diff', $change->diff),
            '',
            $this->marker($change->commit),
        ]);

        return mb_strlen($body) <= self::MAX_CHARACTERS
            ? $body
            : throw ChangeNotShown::because(sprintf('the request with its diff is %d characters, more than one comment holds (%d), so the change must be split', mb_strlen($body), self::MAX_CHARACTERS));
    }

    /**
     * @throws ChangeNotShown
     */
    private function refuseUnshowable(string $diff): void
    {
        if (!mb_check_encoding($diff, 'UTF-8')) {
            throw ChangeNotShown::because('the diff is not UTF-8 text, so a comment cannot show it');
        }

        if (str_contains($diff, "\0")) {
            throw ChangeNotShown::because('the diff holds a NUL byte, so a comment cannot show it');
        }
    }

    private function scanLines(CiScanResult $scan): string
    {
        $lines = array_map(static fn (CiFinding $found): string => sprintf('%s %s line %d: %s: %s', $found->side->value, $found->path, $found->finding->line, $found->finding->risk->value, $found->finding->detail), $scan->findings);

        return implode("\n", [...$lines, ...array_map(static fn (string $path): string => 'ci '.$path, $scan->ciPaths)]);
    }

    // One backtick longer than any run inside, so no line of the text can close the fence and write outside it.
    private function fenced(string $language, string $text): string
    {
        preg_match_all('/`+/', $text, $runs);
        $fence = str_repeat('`', max([self::SHORTEST_FENCE, ...array_map(static fn (string $run): int => strlen($run) + 1, $runs[0])]));

        return $fence.$language."\n".rtrim($text, "\n")."\n".$fence;
    }
}
