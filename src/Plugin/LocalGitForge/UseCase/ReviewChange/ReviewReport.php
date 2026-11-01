<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;

// The JSON follows docs/user/review-output.schema.json, because scripts validate against it.
final readonly class ReviewReport
{
    public function __construct(private VisiblePath $text = new VisiblePath())
    {
    }

    public function asJson(ChangeReview $review): string
    {
        return json_encode([
            'round_id' => $review->roundId,
            'round' => $review->round,
            'decision' => $review->decision->value,
            'exit_code' => ReviewExit::of($review->decision)->value,
            'verdicts' => array_map($this->verdictRow(...), $review->verdicts),
            'act_list' => array_map($this->findingRow(...), $review->actList),
            'tokens' => ['calls' => $review->tokens->calls, 'prompt' => $review->tokens->prompt, 'completion' => $review->tokens->completion, 'total' => $review->tokens->total],
            'reasons' => $review->reasons,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    public function asLines(ChangeReview $review): array
    {
        $lines = [sprintf('%s (round %d)', $review->decision->value, $review->round)];

        foreach ($review->verdicts as $verdict) {
            $lines[] = sprintf('%s reviewer: %s, %d findings', $verdict->tier->value, $verdict->verdict->verdict->value, count($verdict->verdict->findings));
        }

        foreach ($review->reasons as $reason) {
            $lines[] = 'reason: '.$this->text->of($reason);
        }

        $lines[] = sprintf('act list: %d findings', count($review->actList));

        foreach ($review->actList as $finding) {
            $lines[] = sprintf('%s %s %s: %s', $finding->id, $finding->severity->value, $this->place($finding), $this->text->of($finding->text));
        }

        $tokens = $review->tokens;
        $lines[] = sprintf('tokens: %d calls, %d prompt, %d completion, %d total', $tokens->calls, $tokens->prompt, $tokens->completion, $tokens->total);

        return $lines;
    }

    /**
     * @return array<string, int|string>
     */
    private function verdictRow(PanelVerdict $verdict): array
    {
        return ['tier' => $verdict->tier->value, 'decision' => $verdict->verdict->verdict->value, 'findings' => count($verdict->verdict->findings)];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function findingRow(Finding $finding): array
    {
        return [
            'id' => $finding->id,
            'severity' => $finding->severity->value,
            'file' => $finding->file,
            'line' => $finding->line,
            'symbol' => $finding->symbol,
            'text' => $finding->text,
            'fixable_by' => $finding->fixable_by->value,
        ];
    }

    private function place(Finding $finding): string
    {
        if (null === $finding->file) {
            return '(no file)';
        }

        return $this->text->of($finding->file).(null === $finding->line ? '' : ':'.$finding->line);
    }
}
