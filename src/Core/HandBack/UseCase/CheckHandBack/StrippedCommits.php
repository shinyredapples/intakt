<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Domain\AttributionLines;

final readonly class StrippedCommits
{
    // A signature over the old content would not match the new one; the orchestrator signs the commits itself before the push.
    private const array SIGNATURE_HEADERS = ['gpgsig', 'gpgsig-sha256'];

    public function __construct(
        private RepositoryGit $git,
    ) {
    }

    /**
     * @throws HandBackNotChecked
     */
    public function of(GivenHistory $history, string $head): AcceptedHandBack
    {
        $replaced = [];

        foreach ($history->newCommits($head) as $commit) {
            $stripped = $this->stripped($history->repository, $commit, $replaced);

            if ($stripped !== $commit) {
                $replaced[$commit] = $stripped;
            }
        }

        return new AcceptedHandBack($history->base, $replaced[$head] ?? $head, count($replaced));
    }

    /**
     * @param array<string, string> $replaced the new id of each commit rewritten so far
     *
     * @throws HandBackNotChecked
     */
    private function stripped(string $repository, string $commit, array $replaced): string
    {
        [$headers, $message] = explode("\n\n", $this->git->exactOutput($repository, ['cat-file', 'commit', $commit]), 2) + [1 => ''];
        $headerLines = explode("\n", $headers);
        $reparented = $this->reparented($headerLines, $replaced);
        $strippedMessage = AttributionLines::strippedFrom($message);

        if ($reparented === $headerLines && $strippedMessage === $message) {
            return $commit;
        }

        return $this->git->outputWithInput(
            $repository,
            ['hash-object', '-t', 'commit', '-w', '--stdin'],
            implode("\n", $this->unsigned($reparented))."\n\n".$strippedMessage,
        );
    }

    /**
     * @param list<string>          $headerLines
     * @param array<string, string> $replaced
     *
     * @return list<string>
     */
    private function reparented(array $headerLines, array $replaced): array
    {
        return array_map(
            static fn (string $line): string => str_starts_with($line, 'parent ') ? 'parent '.($replaced[substr($line, 7)] ?? substr($line, 7)) : $line,
            $headerLines,
        );
    }

    /**
     * @param list<string> $headerLines
     *
     * @return list<string>
     */
    private function unsigned(array $headerLines): array
    {
        $kept = [];
        $inSignature = false;

        foreach ($headerLines as $line) {
            // A header's value continues on the lines that start with a space.
            if (!str_starts_with($line, ' ')) {
                $inSignature = in_array(explode(' ', $line, 2)[0], self::SIGNATURE_HEADERS, true);
            }

            if (!$inSignature) {
                $kept[] = $line;
            }
        }

        return $kept;
    }
}
