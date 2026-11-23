<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Platform\Contract\Figures;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
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
        private Figures $figures = new Figures(),
    ) {
    }

    public function asJson(ChangeReview $review): string
    {
        return $this->serializer->serialize(ReviewOutput::of($review), JsonEncoder::FORMAT, [
            AbstractNormalizer::ATTRIBUTES => ReviewOutput::FIELDS,
            JsonEncode::OPTIONS => JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ]);
    }

    /**
     * @return list<string>
     */
    public function asLines(ChangeReview $review): array
    {
        $lines = [sprintf('%s (%s)', $review->decision->value, $this->figures->marked('round '.$review->round, ReviewOutput::KINDS['round']))];

        foreach ($review->verdicts as $verdict) {
            $lines[] = sprintf('%s reviewer: %s, %s', $verdict->tier->value, $verdict->verdict->verdict->value, $this->findings(count($verdict->verdict->findings)));
        }

        foreach ($review->reasons as $reason) {
            $lines[] = 'reason: '.$this->text->of($reason);
        }

        $lines[] = 'act list: '.$this->findings(count($review->actList));

        foreach ($review->actList as $finding) {
            // The merged ids join the entry's id, so each finding the entry stands for stays traceable from the command's output in one line; the review page names them below the entry's id instead.
            $lines[] = sprintf('%s %s %s: %s', $this->text->of(implode('+', [$finding->id, ...$finding->merged])), $finding->severity->value, $this->place($finding), $this->text->of($finding->text));
            $judgeLine = $this->judgeLine($finding);
            if (null !== $judgeLine) {
                $lines[] = $judgeLine;
            }
        }

        $tokens = $review->tokens;
        $lines[] = sprintf(
            'tokens: %s, %s',
            $this->figures->marked($tokens->calls.' calls', ReviewOutput::KINDS['calls']),
            $this->figures->marked(sprintf('%d prompt%s, %d completion, %d total', $tokens->prompt, $this->cacheParts($tokens), $tokens->completion, $tokens->total), ReviewOutput::KINDS['tokens']),
        );
        // The tokens and the cost stay on two lines: one is read from the provider, the other worked out from a price list, so no sum of the two is shown.
        $lines[] = 'cost: '.$this->cost($review->cost);

        return $lines;
    }

    private function findings(int $count): string
    {
        return $this->figures->marked($count.' findings', ReviewOutput::KINDS['findings']);
    }

    private function place(Finding $finding): string
    {
        if (null === $finding->file) {
            return '(no file)';
        }

        return $this->text->of($finding->file).(null === $finding->line ? '' : ':'.$finding->line);
    }

    // Only an entry the judge gave a note, so a P1 or P2 the judge did not argue with reads as before the note existed.
    private function judgeLine(Finding $finding): ?string
    {
        $note = $finding->judgeNote();
        if (null === $note) {
            return null;
        }

        return 'judge: '.$this->text->of($note);
    }

    // Only the parts a vendor reported, so a round without caching reads as before.
    private function cacheParts(RoundTokens $tokens): string
    {
        $parts = array_filter(['cache read' => $tokens->cacheRead, 'cache write' => $tokens->cacheWrite, 'cached' => $tokens->cached], static fn (int $count): bool => 0 < $count);
        if ([] === $parts) {
            return '';
        }

        return ' ('.implode(', ', array_map(static fn (string $label, int $count): string => $count.' '.$label, array_keys($parts), $parts)).')';
    }

    // An unknown cost has no figure to mark, because there is nothing to mislabel; an estimate names the day of its price list, or says it is not recorded.
    private function cost(Cost $cost): string
    {
        $reading = $cost->reading($this->text->of(...));
        if (null === $cost->usd) {
            return $reading;
        }

        return $this->figures->marked($reading, ReviewOutput::KINDS['cost']).', '.$cost->priceDateReading();
    }
}
