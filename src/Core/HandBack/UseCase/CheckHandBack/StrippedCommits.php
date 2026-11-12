<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;

final readonly class StrippedCommits
{
    // Because a signature over the old content would not match the new one; the orchestrator signs the commits itself before the push.
    private const array SIGNATURE_HEADERS = ['gpgsig', 'gpgsig-sha256'];

    public function __construct(
        private RepositoryGit $git,
    ) {
    }

    /**
     * @throws HandBackNotChecked
     */
    public function of(GivenHistory $history, string $head): AcceptedHandBack|RefusedHandBack
    {
        $replaced = [];

        foreach ($history->newCommits($head) as $commit) {
            $stripped = $this->stripped($history->repository, $commit, $replaced);

            if (null === $stripped) {
                return new RefusedHandBack(HandBackRefusal::Malformed);
            }

            if ($stripped !== $commit) {
                $replaced[$commit] = $stripped;
            }
        }

        return new AcceptedHandBack($history->base, $replaced[$head] ?? $head, count($replaced));
    }

    /**
     * @param array<string, string> $replaced
     *
     * @throws HandBackNotChecked
     */
    private function stripped(string $repository, string $commit, array $replaced): ?string
    {
        $raw = $this->git->exactOutput($repository, ['cat-file', 'commit', $commit]);
        [$headers, $message] = explode("\n\n", $raw, 2) + [1 => ''];
        $headerLines = explode("\n", $headers);
        $reparented = $this->reparented($headerLines, $replaced);
        $strippedMessage = AttributionLines::strippedFrom($message);

        // Every new commit passes git's own format check, because unbundling checks none and a crafted bundle may hold a commit git would never write.
        if ($reparented === $headerLines && $strippedMessage === $message) {
            return null === $this->git->answerWithInput($repository, ['hash-object', '-t', 'commit', '--stdin'], $raw) ? null : $commit;
        }

        return $this->git->answerWithInput(
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
            // Because a header's value continues on the lines that start with a space.
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
