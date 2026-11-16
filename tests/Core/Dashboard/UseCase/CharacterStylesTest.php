<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CharacterStylesTest extends TestCase
{
    private const string SHEET = __DIR__.'/../../../../assets/styles/components.css';

    public function testACharacterNeitherMovesNorReactsToHover(): void
    {
        foreach ($this->characterRules() as $selector => $body) {
            self::assertStringNotContainsString(':hover', $selector);
            self::assertDoesNotMatchRegularExpression('/\b(?:animation|transition|transform)\b/', $body, $selector);
        }
    }

    public function testTheNearestThemeChoosesWhichImageOfACharacterShows(): void
    {
        $rules = $this->characterRules();

        self::assertStringStartsWith('display: var(--character-light, inline-block);', trim($rules['.character-light']));
        self::assertSame('display: var(--character-dark, none);', trim($rules['.character-dark']));
        self::assertStringContainsString('--character-light: none; --character-dark: inline-block;', $this->rules()['[data-theme="dark"]'] ?? '');
        self::assertStringContainsString('--character-light: inline-block; --character-dark: none;', $this->rules()['[data-theme="light"]'] ?? '');
    }

    public function testALightCharacterIsRingedInMutedOnEverySideSoItsSilhouetteStandsOutFromTheLightSurfaces(): void
    {
        preg_match_all('/drop-shadow\(([^()]*\([^()]*\))\)/', $this->characterRules()['.character-light'] ?? '', $shadows);

        self::assertSame(['1px 0 0 var(--muted)', '-1px 0 0 var(--muted)', '0 1px 0 var(--muted)', '0 -1px 0 var(--muted)'], $shadows[1]);
        self::assertStringNotContainsString('filter', $this->characterRules()['.character-dark']);
    }

    /**
     * @return array<string, string>
     */
    private function characterRules(): array
    {
        $rules = array_filter($this->rules(), static fn (string $selector): bool => str_contains($selector, '.character'), ARRAY_FILTER_USE_KEY);
        self::assertNotEmpty($rules);

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        $sheet = file_get_contents(self::SHEET);
        self::assertIsString($sheet);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $rule): array => [trim($rule[1]), $rule[2]], $rules), 1, 0);
    }
}
