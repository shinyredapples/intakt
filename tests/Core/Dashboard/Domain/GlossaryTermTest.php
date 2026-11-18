<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\GlossaryTerm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlossaryTerm::class)]
final class GlossaryTermTest extends TestCase
{
    private const string DESIGN = __DIR__.'/../../../../docs/design/mocks/DESIGN.md';
    private const string MOCK_SCRIPT = __DIR__.'/../../../../docs/design/mocks/assets/intakt.js';

    public function testEachTermCarriesTheStandardExplanationOfTheDesignGlossaryInItsOrder(): void
    {
        $explanations = array_map(static fn (GlossaryTerm $term): string => $term->explanation(), GlossaryTerm::cases());

        self::assertSame($this->designExplanations(), $explanations);
    }

    public function testEachTermCarriesTheSameExplanationAsTheDashboardMock(): void
    {
        $script = (string) file_get_contents(self::MOCK_SCRIPT);
        $missing = array_filter(
            GlossaryTerm::cases(),
            static fn (GlossaryTerm $term): bool => !str_contains($script, $term->value.': '.json_encode($term->explanation(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        );

        self::assertSame([], array_map(static fn (GlossaryTerm $term): string => $term->value, array_values($missing)));
    }

    /**
     * @return list<string>
     */
    private function designExplanations(): array
    {
        $design = (string) file_get_contents(self::DESIGN);
        if (1 !== preg_match('/^## Glossary\n(.*?)^## /ms', $design, $section)) {
            self::fail('DESIGN.md has no Glossary section.');
        }
        preg_match_all('/^\| (?!Term|---)[^|]+\|[^|]+\| (.+) \|$/m', $section[1], $rows);
        self::assertNotEmpty($rows[1]);

        return $rows[1];
    }
}
