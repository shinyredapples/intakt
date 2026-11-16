<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code, not a model, decides, because a model checking a reviewer can be steered the same way; a finding goes only when none of its quotes is in the change, because a real one also quotes code the change lacks, such as its fix.
#[Exclude]
final readonly class QuotesInChange
{
    // Bytes above 0x7f count as name characters, so a name in another script stays one name, as PHP reads it.
    private const string NAME_CHARACTER = 'A-Za-z0-9_\x80-\xff';

    // Left out, because a fenced block in a finding most often holds the suggested fix, which is not in the change yet; an unclosed fence runs to the end.
    private const string FENCED_BLOCK = '/^[ \t]*(`{3,}|~{3,}).*?(?:^[ \t]*\1[ \t]*$|\z)/ms';

    private const string INLINE_QUOTE = '/(`+)(.+?)\1/';

    // Left out, because a quoted path most often names a file the change should add or update, such as a missing test or document.
    private const string PATH = '#\A[\w.@~-]*/[\w.@~/-]*\z#';

    // Checked name by name, because code never spells a member or a namespaced class this way: `Order::total` is declared as `function total` inside `class Order`.
    private const string REFERENCE = '/\A\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:(?:::|\\\\)[A-Za-z_\x80-\xff][\w\x80-\xff]*)+\z/';

    /**
     * @param list<Finding> $findings
     */
    public function check(array $findings, string $changeText): QuoteCheck
    {
        $kept = [];
        $dropped = [];

        foreach ($findings as $finding) {
            $quotes = $this->quotesOf($finding);

            if ([] === $quotes || array_any($quotes, fn (string $quote): bool => $this->holds($changeText, $quote))) {
                $kept[] = $finding;

                continue;
            }

            $dropped[] = new DroppedFinding($finding->id, $this->reasonFor($quotes));
        }

        return new QuoteCheck($kept, $dropped);
    }

    /**
     * @return list<string>
     */
    private function quotesOf(Finding $finding): array
    {
        preg_match_all(self::INLINE_QUOTE, (string) preg_replace(self::FENCED_BLOCK, '', $finding->text), $inline);
        $quotes = array_map(trim(...), [...(null === $finding->symbol ? [] : [$finding->symbol]), ...$inline[2]]);

        return array_values(array_unique(array_filter($quotes, $this->isCode(...))));
    }

    // Signs alone are left out, because `...` or `;` names no code to look for.
    private function isCode(string $quote): bool
    {
        return 1 === preg_match('/['.self::NAME_CHARACTER.']/', $quote) && 1 !== preg_match(self::PATH, $quote);
    }

    private function holds(string $changeText, string $quote): bool
    {
        // Cut, because empty parentheses only say that the name is called, which the change may do with arguments.
        $fragment = (string) preg_replace('/\(\)\z/', '', $quote);

        if (1 === preg_match(self::REFERENCE, $fragment)) {
            preg_match_all('/[^:\\\\]+/', $fragment, $names);

            return array_all($names[0], fn (string $name): bool => $this->spells($changeText, $name));
        }

        return $this->spells($changeText, $fragment);
    }

    private function spells(string $changeText, string $fragment): bool
    {
        preg_match_all('/\S+/', $fragment, $words);
        $pattern = implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words[0]));
        // A fragment that starts or ends with a name character must not match inside a longer name or number, so `customer` is not in `customerName`.
        $start = 1 === preg_match('/\A['.self::NAME_CHARACTER.']/', $fragment) ? '(?<!['.self::NAME_CHARACTER.'])' : '';
        $end = 1 === preg_match('/['.self::NAME_CHARACTER.']\z/', $fragment) ? '(?!['.self::NAME_CHARACTER.'])' : '';

        // A match that fails on a regular expression limit counts as found, so the judge, not the limit, decides.
        return 0 !== preg_match('/'.$start.$pattern.$end.'/', $changeText);
    }

    /**
     * @param non-empty-list<string> $quotes
     */
    private function reasonFor(array $quotes): string
    {
        return sprintf(
            'None of its quotes is in the change or the files it touches: %s.',
            implode(', ', array_map(static fn (string $quote): string => '`'.$quote.'`', $quotes)),
        );
    }
}
