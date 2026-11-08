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

    public function testEachTermCarriesTheStandardExplanationOfTheDesignGlossaryInItsOrder(): void
    {
        $explanations = array_map(static fn (GlossaryTerm $term): string => $term->explanation(), GlossaryTerm::cases());

        self::assertSame($this->designExplanations(), $explanations);
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
