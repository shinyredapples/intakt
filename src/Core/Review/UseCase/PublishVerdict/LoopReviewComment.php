<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Plugin\Forge\Contract\CommitId;
use UnexpectedValueException;

// The shape of the loop's own reviewer comments, because the loop reads Intakt's verdict as it reads its own reviewers' (issue #1251, D1).
final readonly class LoopReviewComment
{
    private const string HEADING = '**Review by Intakt** (head %s)';
    private const string FOLLOW_UPS = 'Follow-ups:';
    private const int SHORT_HEAD = 12;
    private const string COMMENT_OPENER = '<!--';

    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        private CommitId $head,
        private Decision $decision,
        private array $findings,
        private string $summary,
    ) {
    }

    // The loop reads an open P1 as changes whatever the JSON says, so an approval that lists one is published as changes and the status can follow this verdict.
    public function verdict(): Decision
    {
        $openP1 = array_any($this->findings, static fn (Finding $finding): bool => Severity::P1 === $finding->severity);

        return Decision::Approve === $this->decision && $openP1 ? Decision::Changes : $this->decision;
    }

    public function text(): string
    {
        $verdict = $this->verdict();
        $lines = [sprintf(self::HEADING, substr($this->head->hash, 0, self::SHORT_HEAD)), ''];
        if ([] !== $this->findings) {
            // Filed as follow-ups under an approval, because the loop reads a P2 above that line as changes.
            $listed = array_map($this->lineOf(...), $this->findings);
            $lines = [...$lines, ...(Decision::Approve === $verdict ? [self::FOLLOW_UPS, ...$listed] : $listed), ''];
        }

        $json = json_encode(['verdict' => $verdict->value, 'head' => $this->head->hash, 'summary' => $this->summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", [...$lines, '```json', $json, '```']);
    }

    // On one line, because a line break in a model's text could open a fence or a finding line of its own; the line redacted whole and alone, because a header can start in the place and end in the text, and a redaction that runs to the end of its text must end with its line, not take the verdict block with it.
    private function lineOf(Finding $finding): string
    {
        return new Redaction()->redact(sprintf('[%s] %s: %s', $finding->severity->value, $this->oneLine($this->placeOf($finding)), $this->oneLine($finding->text)))
            |> $this->withoutCommentOpener(...);
    }

    private function placeOf(Finding $finding): string
    {
        if (null === $finding->file) {
            return '(no file)';
        }

        return null === $finding->line ? $finding->file : $finding->file.':'.$finding->line;
    }

    // Unicode line ends too, because a reader may split on them as it splits on a newline.
    private function oneLine(string $text): string
    {
        $line = preg_replace('/[\s\x{85}\x{2028}\x{2029}]+/u', ' ', $text) ?? throw new UnexpectedValueException('A finding holds text that is not valid UTF-8.');

        return trim($line);
    }

    // Broken after the redaction, so nothing can open it again: GitHub hides everything after an opener, the verdict block too, and a quoted marker must not reach the App's comment.
    private function withoutCommentOpener(string $line): string
    {
        return str_replace(self::COMMENT_OPENER, '&lt;!--', $line);
    }
}
