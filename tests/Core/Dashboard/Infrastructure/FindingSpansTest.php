<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\FindingText;
use Intakt\Core\Dashboard\Infrastructure\FindingSpans;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Twig\Environment;

#[CoversClass(FindingSpans::class)]
#[UsesClass(FindingText::class)]
final class FindingSpansTest extends KernelTestCase
{
    public function testTheFindingTitleSpansTwigFunctionDropsATitlesEmphasisMarks(): void
    {
        self::assertSame('Retry the call|0', $this->rendered('finding_title_spans', '**Retry** the call'));
    }

    public function testTheFindingTitleSpansTwigFunctionCutsALongTitleTo160CharactersWithAnEllipsis(): void
    {
        self::assertSame(str_repeat('a', 159).'…|0', $this->rendered('finding_title_spans', str_repeat('a', 200)));
    }

    public function testTheFindingSpansTwigFunctionReturnsABacktickSpanAsCode(): void
    {
        self::assertSame('Call |0write()|1', $this->rendered('finding_spans', 'Call `write()`'));
    }

    private function rendered(string $function, string $text): string
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate('{% for span in '.$function.'(text) %}{{ span.text }}|{{ span.code ? 1 : 0 }}{% endfor %}')->render(['text' => $text]);
    }
}
