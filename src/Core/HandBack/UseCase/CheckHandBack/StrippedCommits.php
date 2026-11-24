<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Plugin\Forge\Contract\JobCredentials;

final readonly class StrippedCommits
{
    // Because a signature over the old content would not match the new one; the orchestrator signs the commits itself before the push.
    private const array SIGNATURE_HEADERS = ['gpgsig', 'gpgsig-sha256'];

    /**
     * @throws HandBackNotChecked
     */
    public function of(GivenHistory $history, string $head, JobCredentials $credentials): AcceptedHandBack|RefusedHandBack
    {
        $replaced = [];
        $redaction = $this->redactionOf($credentials);

        foreach ($history->newCommits($head) as $commit) {
            $raw = $history->git->exactOutput($history->repository, ['cat-file', 'commit', $commit]);
            $rewritten = $this->rewritten($raw, $replaced, $redaction);
            $stripped = null === $rewritten ? $this->unchanged($history, $commit, $raw) : $this->written($history, $rewritten);

            if (null === $stripped) {
                return new RefusedHandBack(HandBackRefusal::Malformed);
            }

            if ($stripped !== $commit) {
                $replaced[$commit] = $stripped;
            }
        }

        return new AcceptedHandBack($history->pushed, $replaced[$head] ?? $head, count($replaced));
    }

    /**
     * @return array<string, string>
     */
    private function redactionOf(JobCredentials $credentials): array
    {
        // For strtr(), which tries the longest value first, so a credential that contains another is redacted whole.
        return array_fill_keys([...$credentials], Redaction::MARKER);
    }

    /**
     * @param array<string, string> $replaced
     * @param array<string, string> $redaction
     */
    private function rewritten(string $raw, array $replaced, array $redaction): ?string
    {
        [$headers, $message] = explode("\n\n", $raw, 2) + [1 => ''];
        $headerLines = explode("\n", $headers);
        $reparented = $this->redactedHeaders($this->reparented($headerLines, $replaced), $redaction);
        $strippedMessage = strtr(AttributionLines::strippedFrom($message), $redaction);

        if ($reparented === $headerLines && $strippedMessage === $message) {
            return null;
        }

        return implode("\n", $this->unsigned($reparented))."\n\n".$strippedMessage;
    }

    // Every new commit passes git's own format check, because unbundling checks none and a crafted bundle may hold a commit git would never write.
    private function unchanged(GivenHistory $history, string $commit, string $raw): ?string
    {
        return null === $history->git->answerWithInput($history->repository, ['hash-object', '-t', 'commit', '--stdin'], $raw) ? null : $commit;
    }

    private function written(GivenHistory $history, string $rewritten): ?string
    {
        return $history->git->answerWithInput($history->repository, ['hash-object', '-t', 'commit', '-w', '--stdin'], $rewritten);
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
     * @param list<string>          $headerLines
     * @param array<string, string> $redaction
     *
     * @return list<string>
     */
    private function redactedHeaders(array $headerLines, array $redaction): array
    {
        // Every header but the object ids, because a credential can be in an author's or committer's name as well as in the message.
        return array_map(
            static fn (string $line): string => str_starts_with($line, 'tree ') || str_starts_with($line, 'parent ') ? $line : strtr($line, $redaction),
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
