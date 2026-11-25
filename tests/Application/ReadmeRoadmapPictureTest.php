<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReadmeRoadmapPictureTest extends TestCase
{
    private const array MILESTONES = ['M0 Unblock', 'M1 Review core', 'M2 Panel and rounds', 'M3 Agents and memory', 'M4 Live dashboard', 'M5 Demo', 'M6 Reviewer for the loop', 'M7 Builder half', 'M8 Switch'];

    public function testTheRoadmapPictureServesTheDarkImageForADarkColorScheme(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<source media="\(prefers-color-scheme: dark\)" srcset="docs\/assets\/roadmap-dark\.svg" width="420" height="245">/', $picture));
        self::assertFileExists($this->root().'/docs/assets/roadmap-dark.svg');
    }

    public function testTheRoadmapPictureServesTheLightImageOtherwise(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<img src="docs\/assets\/roadmap-light\.svg" alt=/', $picture));
        self::assertFileExists($this->root().'/docs/assets/roadmap-light.svg');
    }

    // A page may show the image at another size than its 480 by 280 view box, so the size must keep that ratio.
    public function testTheRoadmapPictureKeepsTheAspectRatioOfBothImageFiles(): void
    {
        $width = (int) $this->imgAttribute('width');
        $height = (int) $this->imgAttribute('height');

        foreach (['light', 'dark'] as $theme) {
            $svg = (string) file_get_contents($this->root().'/docs/assets/roadmap-'.$theme.'.svg');

            self::assertStringContainsString('viewBox="0 0 480 280"', $svg);
        }
        self::assertLessThanOrEqual(480, $width);
        self::assertSame((int) round($width * 280 / 480), $height);
    }

    // A 390 px wide phone shows the 480 wide view box at 0.81 of its size, so 15 px text still shows at about 12 px.
    public function testBothRoadmapImagesKeepEveryFontSizeReadableOnA390PixelWideScreen(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $svg = (string) file_get_contents($this->root().'/docs/assets/roadmap-'.$theme.'.svg');

            self::assertGreaterThan(0, preg_match_all('/font-size:\s*(\d+(?:\.\d+)?)px/', $svg, $sizes));
            foreach ($sizes[1] as $size) {
                self::assertGreaterThanOrEqual(12.0, (float) $size * 390 / 480);
            }
        }
    }

    // GitHub shows an SVG as an image, so the octopus must be inside the file and the file small enough to load fast.
    public function testBothRoadmapImagesEmbedTheOctopusAndStaySmall(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $file = $this->root().'/docs/assets/roadmap-'.$theme.'.svg';
            $svg = (string) file_get_contents($file);

            self::assertSame(1, preg_match('/href="data:image\/webp;base64,([A-Za-z0-9+\/=]+)"/', $svg, $match));
            $webp = base64_decode($match[1] ?? '', true);
            self::assertIsString($webp);
            self::assertNotSame('', $webp);
            // A WebP file is a RIFF container: "RIFF", the length of the rest as 32-bit little endian, then "WEBP", so a cut or empty payload fails.
            self::assertSame('RIFF', substr($webp, 0, 4));
            self::assertSame('WEBP', substr($webp, 8, 4));
            $length = unpack('V', substr($webp, 4, 4));
            self::assertIsArray($length);
            self::assertSame(strlen($webp) - 8, $length[1]);
            self::assertLessThan(120_000, filesize($file));
        }
    }

    public function testTheRoadmapPictureAltStatesEveryMilestoneAndWhereWeAre(): void
    {
        $alt = $this->imgAttribute('alt');

        self::assertStringContainsString('started 24 Oct by a custom build loop', $alt);
        self::assertStringContainsString('we are here', $alt);
        self::assertStringContainsString('22 Nov', $alt);
        foreach (self::MILESTONES as $milestone) {
            self::assertStringContainsString($milestone, $alt);
        }
    }

    public function testTheRoadmapPictureAltPutsWeAreHereAtM6AfterM5Done(): void
    {
        $alt = $this->imgAttribute('alt');

        $m5 = strpos($alt, 'M5 Demo');
        $here = strpos($alt, 'we are here');
        $m6 = strpos($alt, 'M6 Reviewer for the loop');

        self::assertIsInt($m5);
        self::assertIsInt($here);
        self::assertIsInt($m6);
        self::assertLessThan($here, $m5);
        self::assertLessThan($m6, $here);
    }

    public function testBothRoadmapImagesNameM6ReviewerForTheLoopAndNoStaleLabel(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $svg = (string) file_get_contents($this->root().'/docs/assets/roadmap-'.$theme.'.svg');

            self::assertStringContainsString('Reviewer for the loop', $svg);
            self::assertStringContainsString('reviews the build', $svg);
            self::assertStringNotContainsString('Runs alongside', $svg);
            self::assertStringNotContainsString('beside the build loop', $svg);
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
