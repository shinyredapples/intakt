<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Plugin\Forge\Contract\CommitId;

// The shape of the loop's own reviewer comments, because the loop reads Intakt's verdict as it reads its own reviewers' (issue #1251, D1).
final readonly class LoopReviewComment
{
    private const string HEADING = '**Review by Intakt** (head %s)';
    private const int SHORT_HEAD = 12;

    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        private CommitId $head,
        private Decision $verdict,
        private array $findings,
        private string $summary,
    ) {
    }

    public function text(): string
    {
        $lines = [sprintf(self::HEADING, substr($this->head->hash, 0, self::SHORT_HEAD)), ''];
        foreach ($this->findings as $finding) {
            $lines[] = $this->lineOf($finding);
        }

        if ([] !== $this->findings) {
            $lines[] = '';
        }

        $json = json_encode(['verdict' => $this->verdict->value, 'head' => $this->head->hash, 'summary' => $this->summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", [...$lines, '```json', $json, '```']);
    }

    // On one line, because a line break in a model's text could open a fence or a finding line of its own.
    private function lineOf(Finding $finding): string
    {
        return sprintf('[%s] %s: %s', $finding->severity->value, $this->oneLine($this->placeOf($finding)), $this->oneLine($finding->text));
    }

    private function placeOf(Finding $finding): string
    {
        if (null === $finding->file) {
            return '(no file)';
        }

        return null === $finding->line ? $finding->file : $finding->file.':'.$finding->line;
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
