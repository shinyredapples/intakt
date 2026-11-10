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
    public static function of(CommitId $head, Decision $verdict, array $findings, string $summary): string
    {
        $lines = [sprintf(self::HEADING, substr($head->hash, 0, self::SHORT_HEAD)), ''];
        foreach ($findings as $finding) {
            $lines[] = self::lineOf($finding);
        }

        if ([] !== $findings) {
            $lines[] = '';
        }

        $json = json_encode(['verdict' => $verdict->value, 'head' => $head->hash, 'summary' => $summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", [...$lines, '```json', $json, '```']);
    }

    // On one line, because a line break in a model's text could open a fence or a finding line of its own.
    private static function lineOf(Finding $finding): string
    {
        return sprintf('[%s] %s: %s', $finding->severity->value, self::oneLine(self::placeOf($finding)), self::oneLine($finding->text));
    }

    private static function placeOf(Finding $finding): string
    {
        if (null === $finding->file) {
            return '(no file)';
        }

        return null === $finding->line ? $finding->file : $finding->file.':'.$finding->line;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
