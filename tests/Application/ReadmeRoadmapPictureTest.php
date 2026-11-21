<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReadmeRoadmapPictureTest extends TestCase
{
    private const array MILESTONES = ['M0 Unblock', 'M1 Review core', 'M2 Panel and rounds', 'M3 Agents and memory', 'M4 Live dashboard', 'M5 Demo', 'M6 Shadow', 'M7 Builder half', 'M8 Switch'];

    public function testTheRoadmapPictureServesTheDarkImageForADarkColorScheme(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<source media="\(prefers-color-scheme: dark\)" srcset="docs\/assets\/roadmap-dark\.svg" width="560" height="156">/', $picture));
        self::assertFileExists($this->root().'/docs/assets/roadmap-dark.svg');
    }

    public function testTheRoadmapPictureServesTheLightImageOtherwise(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<img src="docs\/assets\/roadmap-light\.svg" alt=/', $picture));
        self::assertFileExists($this->root().'/docs/assets/roadmap-light.svg');
    }

    // The README shows the image smaller than its 760 by 212 view box, so the size must keep that ratio.
    public function testTheRoadmapPictureKeepsTheAspectRatioOfBothImageFiles(): void
    {
        $width = (int) $this->imgAttribute('width');
        $height = (int) $this->imgAttribute('height');

        foreach (['light', 'dark'] as $theme) {
            $svg = (string) file_get_contents($this->root().'/docs/assets/roadmap-'.$theme.'.svg');

            self::assertStringContainsString('viewBox="0 0 760 212"', $svg);
        }
        self::assertLessThanOrEqual(760, $width);
        self::assertSame((int) round($width * 212 / 760), $height);
    }

    // GitHub shows an SVG as an image, so the octopus must be inside the file and the file small enough to load fast.
    public function testBothRoadmapImagesEmbedTheOctopusAndStaySmall(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $file = $this->root().'/docs/assets/roadmap-'.$theme.'.svg';
            $svg = (string) file_get_contents($file);

            self::assertStringContainsString('href="data:image/webp;base64,', $svg);
            self::assertLessThan(120_000, filesize($file));
        }
    }

    public function testTheRoadmapPictureAltStatesEveryMilestoneAndWhereWeAre(): void
    {
        $alt = $this->imgAttribute('alt');

        self::assertStringContainsString('started 24 Oct', $alt);
        self::assertStringContainsString('we are here', $alt);
        self::assertStringContainsString('22 Nov', $alt);
        foreach (self::MILESTONES as $milestone) {
            self::assertStringContainsString($milestone, $alt);
        }
    }

    public function testTheRoadmapPictureLoadsLazily(): void
    {
        self::assertSame('lazy', $this->imgAttribute('loading'));
    }

    public function testTheRoadmapPictureOpensTheStatusSection(): void
    {
        $readme = $this->readme();
        $status = strpos($readme, '## Status');
        self::assertIsInt($status);

        $picture = (int) strpos($readme, 'srcset="docs/assets/roadmap-dark.svg"', $status);
        $worksToday = (int) strpos($readme, '**Works today:**', $status);

        self::assertGreaterThan($status, $picture);
        self::assertLessThan($worksToday, $picture);
    }

    private function picture(): string
    {
        $found = preg_match('/<picture>\s*<source media="\(prefers-color-scheme: dark\)" srcset="docs\/assets\/roadmap-dark[^>]*>.*?<\/picture>/s', $this->readme(), $match);

        self::assertSame(1, $found);

        return $match[0];
    }

    private function imgAttribute(string $name): string
    {
        $found = preg_match('/<img[^>]*\s'.$name.'="([^"]*)"/', $this->picture(), $match);

        self::assertSame(1, $found);

        return $match[1];
    }

    private function readme(): string
    {
        return (string) file_get_contents($this->root().'/README.md');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
