<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The owner chooses the Technical writer's animal from this page, so it must be reachable from the mocks index and readable on a phone.
#[CoversNothing]
final class TechnicalWriterSketchesTest extends TestCase
{
    private const string MOCKS = __DIR__.'/../../docs/design/mocks/';
    private const string PAGE = 'technical-writer-sketches.html';

    public function testTheMocksReadmeLinksToTheSketchesPage(): void
    {
        $readme = (string) file_get_contents(self::MOCKS.'README.md');

        self::assertMatchesRegularExpression('~\]\((?:\./)?'.preg_quote(self::PAGE, '~').'\)~', $readme);
    }

    public function testTheSketchCardsNeverAskForMoreThanTheAvailableWidth(): void
    {
        $style = $this->style();
        preg_match_all('~minmax\(\s*([^,]+),~', $style, $minimums);

        self::assertNotSame([], $minimums[1]);
        foreach ($minimums[1] as $minimum) {
            self::assertStringStartsWith('min(100%', trim($minimum));
        }
    }

    public function testTheSketchFiguresShrinkWithTheirCard(): void
    {
        preg_match('~\.pair img, \.pair svg \{([^}]*)\}~', $this->style(), $rule);

        self::assertArrayHasKey(1, $rule);
        self::assertDoesNotMatchRegularExpression('~(?<![-\w])(?:min-)?width:\s*\d+px~', $rule[1]);
        self::assertMatchesRegularExpression('~max-width:\s*186px~', $rule[1]);
    }

    private function style(): string
    {
        preg_match('~<style>(.*?)</style>~s', (string) file_get_contents(self::MOCKS.self::PAGE), $style);

        return $style[1] ?? '';
    }
}
