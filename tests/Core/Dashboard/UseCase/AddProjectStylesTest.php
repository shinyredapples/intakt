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
        $links = $this->block('/* The progress keeps only its bars');
        $sheet = $this->sheet();
        $link = $this->rule('.wizard .steps a', $links);
        $reach = $this->pixels('/top:\s*-(\d+)px;/', $link);
        $crumbs = $this->rule('.crumbs', $sheet);
        $steps = $this->rule('.add-project .steps', $this->block('/* The progress links reach'));
        $gap = $this->spacing('margin-bottom', $crumbs) + $this->spacing('margin-top', $steps);

        self::assertGreaterThanOrEqual($reach, $gap);
    }

    public function testTheStepCountShowsAtEveryWidthAsInTheMock(): void
    {
        $flow = $this->block('/* The Add project flow shows its step count');

        self::assertMatchesRegularExpression('/display:\s*block;/', $this->rule('.add-project .step-count', $flow));
    }

    public function testOnAPhoneTheShareSliderIsATouchTargetAtLeast44PxTall(): void
    {
        $flow = $this->block('/* The progress links reach');

        self::assertGreaterThanOrEqual(44, $this->pixels('/height:\s*(\d+)px;/', $this->rule('.share-row input[type="range"]', $flow)));
    }

    // Up to the end of the comment's media block, or of the sheet, because a selector such as .wizard .steps a has other rules at other widths.
    private function block(string $comment): string
    {
        $start = strpos($this->sheet(), $comment);
        self::assertIsInt($start, $comment);
        $rest = substr($this->sheet(), $start);
        $end = strpos($rest, "\n}");

        return false === $end ? $rest : substr($rest, 0, $end);
    }

    private function sheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/assets/styles/components.css');
    }

    private function rule(string $selector, string $css): string
    {
        return $this->firstGroup('/(?:^|\n)\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $css);
    }

    private function pixels(string $pattern, string $declarations): int
    {
        return (int) $this->firstGroup($pattern, $declarations);
    }

    // In pixels, because each spacing token is named by its size, and a margin may be a sum of them in calc().
    private function spacing(string $property, string $declarations): int
    {
        self::assertGreaterThan(0, preg_match_all('/var\(--s(\d+)\)/', $this->firstGroup('/'.$property.':\s*([^;]+);/', $declarations), $tokens));

        return array_sum(array_map(intval(...), $tokens[1]));
    }

    private function firstGroup(string $pattern, string $text): string
    {
        if (1 !== preg_match($pattern, $text, $match)) {
            self::fail('No match for '.$pattern);
        }

        return $match[1];
    }
}
