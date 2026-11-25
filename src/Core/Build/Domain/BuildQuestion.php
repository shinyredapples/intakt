<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use JsonException;
use stdClass;

// Checked by plain code before anything trusts it, because the builder wrote the text and people read it later.
final readonly class BuildQuestion
{
    // In the checkout root next to PR.md, because the build script copies only what the builder leaves there.
    public const string FILE = 'QUESTION.json';

    // A limit, because a steered builder could otherwise fill the comment people read; a real question is a few sentences.
    public const int MAX_BYTES = 8192;

    /**
     * @param non-empty-list<string> $choices
     */
    private function __construct(
        public string $question,
        public array $choices,
        public string $recommendation,
    ) {
    }

    /**
     * @param non-empty-list<BugPart> $missing
     */
    public static function forMissingBugParts(array $missing): self
    {
        $names = implode(', ', array_map(static fn (BugPart $part): string => $part->value, $missing));
        $add = 'Add the missing parts to the task';

        return new self(
            sprintf('This bug task is missing: %s. A bug task states When (the steps or input), Today (what happens), Expected (what should happen) and Must keep working (what the fix must not change).', $names),
            [$add, 'Remove the bug label'],
            $add,
        );
    }

    /**
     * @throws QuestionRefused
     */
    public static function fromHandedBack(string $text): self
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw QuestionRefused::tooLong(self::MAX_BYTES);
        }

        $data = self::decoded($text);

        return self::accepted($data['question'] ?? null, $data['choices'] ?? null, $data['recommendation'] ?? null);
    }

    // Null for texts the rule would refuse or trim, or that no accepted hand-back could hold, because only an accepted question is ever stored and it is stored trimmed.
    public static function fromStored(mixed $question, mixed $choices, mixed $recommendation): ?self
    {
        try {
            $accepted = self::accepted($question, $choices, $recommendation);
        } catch (QuestionRefused) {
            return null;
        }

        // The texts together, because all of them came out of one hand-back and decoding JSON never makes a text longer.
        if (strlen($accepted->question.implode('', $accepted->choices).$accepted->recommendation) > self::MAX_BYTES) {
            return null;
        }

        return [$question, $choices, $recommendation] === [$accepted->question, $accepted->choices, $accepted->recommendation] ? $accepted : null;
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(string $text): array
    {
        // Decoded to objects, because an associative decoding turns {"0":"a"} into a list and so an object into choices.
        try {
            $data = json_decode(self::withDecodableKeys($text), false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw QuestionRefused::notJson();
        }

        return $data instanceof stdClass ? get_object_vars($data) : throw QuestionRefused::notJson();
    }

    // A key that starts with NUL gets another first character, because PHP decodes no object with one and an unknown key is ignored, never refused; every string is matched whole, so no value is changed.
    private static function withDecodableKeys(string $text): string
    {
        return preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*+"(?:[ \t\n\r]*+:)?/s',
            static fn (array $string): string => str_starts_with($string[0], '"\u0000') && str_ends_with($string[0], ':') ? '"\u0001'.substr($string[0], 7) : $string[0],
            $text,
        ) ?? $text;
    }

    /**
     * @throws QuestionRefused
     */
    private static function accepted(mixed $givenQuestion, mixed $givenChoices, mixed $givenRecommendation): self
    {
        $question = self::textOf($givenQuestion);

        if ('' === $question) {
            throw QuestionRefused::emptyQuestion();
        }

        $choices = self::choicesOf($givenChoices);
        $recommendation = self::textOf($givenRecommendation);

        if (!in_array($recommendation, $choices, true)) {
            throw QuestionRefused::recommendationNotAChoice();
        }

        return new self($question, $choices, $recommendation);
    }

    private static function textOf(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return non-empty-list<string>
     */
    private static function choicesOf(mixed $value): array
    {
        if (!is_array($value) || [] === $value || !array_is_list($value)) {
            throw QuestionRefused::noChoices();
        }

        $choices = [];

        foreach ($value as $choice) {
            $text = self::textOf($choice);
            $choices[] = '' === $text ? throw QuestionRefused::emptyChoice() : $text;
        }

        // Compared after trimming, because two choices that print alike leave the reader unable to tell which one the recommendation names.
        return count(array_unique($choices)) === count($choices) ? $choices : throw QuestionRefused::duplicateChoice();
    }
}
