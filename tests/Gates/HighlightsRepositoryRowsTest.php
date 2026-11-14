<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\HighlightAssembly;
use Intakt\Gates\HighlightFragment;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\HighlightLayout;
use Intakt\Gates\HighlightRowKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HighlightFragments::class)]
#[UsesClass(HighlightAssembly::class)]
#[UsesClass(HighlightFragment::class)]
#[UsesClass(HighlightLayout::class)]
#[UsesClass(HighlightRowKind::class)]
final class HighlightsRepositoryRowsTest extends TestCase
{
    public function testTheAssembledDecorationRowDoesNotCiteARemovedPlanTask(): void
    {
        $assembly = new HighlightFragments()->assembled(dirname(__DIR__, 2));

        $rows = array_filter(
            explode("\n", $assembly->text),
            static fn (string $line): bool => str_contains($line, 'DependencyInjection service decoration'),
        );

        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertDoesNotMatchRegularExpression('/\bT4[12]\b/', $row);
        }
    }
}
