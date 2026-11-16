<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code, not a model, decides, because a model checking a reviewer can be steered the same way. Only the names of a quote are looked up, because a reviewer shortens and reformats the code it quotes, and a finding goes only when none of its quotes is in the change, because a real one also quotes code the change lacks, such as its fix. The symbol is never checked and a finding without a quote goes to the judge, because neither shows code the reviewer claims to have read.
#[Exclude]
final readonly class QuotesInChange
{
    // Bytes above 0x7f count as name characters, so a name in another script stays one name, as PHP reads it.
    private const string NAME_CHARACTER = 'A-Za-z0-9_\x80-\xff';

    // A run that starts with a digit is a number and is not looked up, because a real finding also quotes a value the change should hold instead.
    private const string NAME = '/(?<!['.self::NAME_CHARACTER.'])[A-Za-z_\x80-\xff]['.self::NAME_CHARACTER.']*/';

    // Left out, because a fenced block in a finding most often holds the suggested fix, which is not in the change yet; an unclosed fence runs to the end.
    private const string FENCED_BLOCK = '/^[ \t]*(`{3,}|~{3,}).*?(?:^[ \t]*\1[ \t]*$|\z)/ms';

    private const string INLINE_QUOTE = '/(`+)(.+?)\1/';

    // Left out, because a quoted path most often names a file the change should add or update, such as a missing test or document.
    private const string PATH = '#\A[\w.@~-]*/[\w.@~/-]*\z#';

    /**
     * @param list<Finding> $findings
     */
    public function check(array $findings, string $changeText): QuoteCheck
    {
        $kept = [];
        $dropped = [];

        foreach ($findings as $finding) {
            $reason = $this->reasonToDrop($finding, $changeText);

            if (null === $reason) {
                $kept[] = $finding;

                continue;
            }

            $dropped[] = new DroppedFinding($finding->id, $reason);
        }

        return new QuoteCheck($kept, $dropped);
    }

    private function reasonToDrop(Finding $finding, string $changeText): ?string
    {
        // The symbol is never read: it says where a finding is, like its file and line, and is often free text, so a finding that quotes nothing goes to the judge whatever its symbol.
        $quotes = $this->quotesIn($finding->text);

        if ([] === $quotes || array_any($quotes, fn (string $quote): bool => $this->holds($changeText, $quote))) {
            return null;
        }

        return sprintf(
            'None of its quotes is in the change or the files it touches: %s.',
            implode(', ', array_map(static fn (string $quote): string => '`'.$quote.'`', $quotes)),
        );
    }

    /**
     * @return list<string>
     */
    private function quotesIn(string $text): array
    {
        preg_match_all(self::INLINE_QUOTE, (string) preg_replace(self::FENCED_BLOCK, '', $text), $inline);

        return array_values(array_unique(array_filter(array_map(trim(...), $inline[2]), $this->namesCode(...))));
    }

    // Signs and numbers alone are left out, because `...` or `>= 10` holds no name to look for.
    private function namesCode(string $quote): bool
    {
        return 1 === preg_match(self::NAME, $quote) && 1 !== preg_match(self::PATH, $quote);
    }

    private function holds(string $changeText, string $quote): bool
    {
        preg_match_all(self::NAME, $quote, $names);

        return array_all(array_unique($names[0]), fn (string $name): bool => $this->names($changeText, $name));
    }

    private function names(string $changeText, string $name): bool
    {
        // A name must not match inside a longer name or number, so `customer` is not in `customerName`.
        $pattern = '/(?<!['.self::NAME_CHARACTER.'])'.preg_quote($name, '/').'(?!['.self::NAME_CHARACTER.'])/';

        // A match that fails on a regular expression limit counts as found, so the judge, not the limit, decides.
        return 0 !== preg_match($pattern, $changeText);
    }
}
