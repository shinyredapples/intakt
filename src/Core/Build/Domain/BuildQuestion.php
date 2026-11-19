<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use JsonException;

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
     * @throws QuestionRefused
     */
    public static function fromHandedBack(string $text): self
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw QuestionRefused::tooLong(self::MAX_BYTES);
        }

        $data = self::decoded($text);
        $question = self::textOf($data['question'] ?? null);

        if ('' === $question) {
            throw QuestionRefused::emptyQuestion();
        }

        $choices = self::choicesOf($data['choices'] ?? null);
        $recommendation = self::textOf($data['recommendation'] ?? null);

        if (!in_array($recommendation, $choices, true)) {
            throw QuestionRefused::recommendationNotAChoice();
        }

        return new self($question, $choices, $recommendation);
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(string $text): array
    {
        try {
            $data = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw QuestionRefused::notJson();
        }

        return is_array($data) && !array_is_list($data) ? $data : throw QuestionRefused::notJson();
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

        return $choices;
    }
}
