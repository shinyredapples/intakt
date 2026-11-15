<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code, not a model, decides, because a reviewer that invents a name would only be checked by another model that can be steered the same way; a rule, not a service.
#[Exclude]
final readonly class QuotesInChange
{
    // Bytes above 0x7f count as letters, so a name in another script stays one name, as PHP reads it.
    private const string NAME = '/[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/';

    // Left out, because a fenced block in a finding most often holds the suggested fix, which is not in the change yet; an unclosed fence runs to the end.
    private const string FENCED_BLOCK = '/^[ \t]*(`{3,}|~{3,}).*?(?:^[ \t]*\1[ \t]*$|\z)/ms';

    private const string INLINE_QUOTE = '/(`+)(.+?)\1/';

    /**
     * @param list<Finding> $findings
     */
    public function check(array $findings, string $changeText): QuoteCheck
    {
        $known = array_flip($this->namesIn($changeText));
        $kept = [];
        $dropped = [];

        foreach ($findings as $finding) {
            $absent = array_values(array_filter($this->quotedNamesOf($finding), static fn (string $name): bool => !isset($known[$name])));

            if ([] === $absent) {
                $kept[] = $finding;

                continue;
            }

            $dropped[] = new DroppedFinding($finding->id, $this->reasonFor($absent));
        }

        return new QuoteCheck($kept, $dropped);
    }

    /**
     * @return list<string>
     */
    private function quotedNamesOf(Finding $finding): array
    {
        $quotes = null === $finding->symbol ? [] : [$finding->symbol];
        preg_match_all(self::INLINE_QUOTE, (string) preg_replace(self::FENCED_BLOCK, '', $finding->text), $inline);

        return array_values(array_unique(array_merge([], ...array_map($this->namesIn(...), [...$quotes, ...$inline[2]]))));
    }

    /**
     * @return list<string>
     */
    private function namesIn(string $text): array
    {
        preg_match_all(self::NAME, $text, $names);

        return $names[0];
    }

    /**
     * @param non-empty-list<string> $absent
     */
    private function reasonFor(array $absent): string
    {
        return sprintf(
            'It quotes %s, which %s in neither the change nor the files it touches.',
            implode(', ', array_map(static fn (string $name): string => '`'.$name.'`', $absent)),
            1 === count($absent) ? 'is' : 'are',
        );
    }
}
