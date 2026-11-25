<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as RunSetupStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class AddProjectStylesTest extends TestCase
{
    public function testOnAPhoneNoProgressLinkReachesIntoTheBreadcrumb(): void
    {
        $reach = $this->pixels('/top:\s*-(\d+)px;/', $this->rule('.wizard .steps a', $this->block('/* The progress keeps only its bars')));
        $gap = $this->spacing('margin-bottom', $this->rule('.crumbs', $this->sheet()))
            + $this->spacing('margin-top', $this->rule('.add-project .steps', $this->block('/* The progress links reach')));

        self::assertGreaterThanOrEqual($reach, $gap);
    }

    public function testTheStepCountShowsAtEveryWidthAsInTheMock(): void
    {
        self::assertMatchesRegularExpression('/display:\s*block;/', $this->rule('.add-project .step-count', $this->block('/* The Add project flow shows its step count')));
    }

    public function testOnAPhoneTheShareSliderIsATouchTargetAtLeast44PxTall(): void
    {
        self::assertGreaterThanOrEqual(44, $this->pixels('/height:\s*(\d+)px;/', $this->rule('.share-row input[type="range"]', $this->block('/* The progress links reach'))));
    }

    private function sheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/assets/styles/components.css');
    }

    // The rules from a comment to the end of its media block, or of the sheet.
    private function block(string $comment): string
    {
        $start = strpos($this->sheet(), $comment);
        self::assertIsInt($start, $comment);
        $rest = substr($this->sheet(), $start);
        $end = strpos($rest, "\n}");

        return false === $end ? $rest : substr($rest, 0, $end);
    }

    private function rule(string $selector, string $css): string
    {
        self::assertSame(1, preg_match('/(?:^|\n)\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $css, $match), $selector);

        return $match[1];
    }

    private function pixels(string $pattern, string $declarations): int
    {
        self::assertSame(1, preg_match($pattern, $declarations, $match), $pattern);

        return (int) $match[1];
    }

    // A margin of one spacing token, or of a sum of them in calc(), in pixels, since each token is named by its size.
    private function spacing(string $property, string $declarations): int
    {
        self::assertSame(1, preg_match('/'.$property.':\s*([^;]+);/', $declarations, $match), $property);
        self::assertGreaterThan(0, preg_match_all('/var\(--s(\d+)\)/', $match[1], $tokens));

        return array_sum(array_map(intval(...), $tokens[1]));
    }
}
