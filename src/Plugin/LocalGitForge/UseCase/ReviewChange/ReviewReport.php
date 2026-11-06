<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

// The JSON follows docs/user/review-output.schema.json, because scripts validate against it.
final readonly class ReviewReport
{
    // The stored serializer, because its snake_case name converter gives the documented keys (round_id, exit_code, act_list).
    public function __construct(
        #[Target('stored')]
        private SerializerInterface $serializer,
        private VisiblePath $text = new VisiblePath(),
    ) {
    }

    public function asJson(ChangeReview $review): string
    {
        return $this->serializer->serialize(ReviewOutput::of($review), 'json', [
            AbstractNormalizer::ATTRIBUTES => ReviewOutput::FIELDS,
            JsonEncode::OPTIONS => JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ]);
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

    private function place(Finding $finding): string
    {
        if (null === $finding->file) {
            return '(no file)';
        }

        return $this->text->of($finding->file).(null === $finding->line ? '' : ':'.$finding->line);
    }
}
