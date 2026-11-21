<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReadmeTeamPictureTest extends TestCase
{
    private const array ROLES = ['Tech lead', 'Developer', 'Code reviewer', 'Judge', 'Security engineer', 'Designer', 'AI engineer', 'Maintainer'];

    public function testTheTeamPictureServesTheDarkImageForADarkColorScheme(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<source media="\(prefers-color-scheme: dark\)" srcset="docs\/assets\/team-dark-1x\.webp 1x, docs\/assets\/team-dark\.webp 2x" width="760" height="116">/', $picture));
        self::assertFileExists($this->root().'/docs/assets/team-dark.webp');
        self::assertFileExists($this->root().'/docs/assets/team-dark-1x.webp');
    }

    public function testTheTeamPictureDimensionsMatchTheImageFilesTheyDescribe(): void
    {
        foreach (['light' => 113, 'dark' => 116] as $theme => $height) {
            $oneX = getimagesize($this->root().'/docs/assets/team-'.$theme.'-1x.webp');
            $twoX = getimagesize($this->root().'/docs/assets/team-'.$theme.'.webp');

            self::assertIsArray($oneX);
            self::assertIsArray($twoX);
            self::assertSame([760, $height], [$oneX[0], $oneX[1]]);
            self::assertSame($height, (int) round($twoX[1] * 760 / $twoX[0]));
            self::assertGreaterThanOrEqual(1520, $twoX[0]);
        }
    }

    public function testTheTeamPictureServesTheLightImageOtherwise(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<img src="docs\/assets\/team-light-1x\.webp" srcset="docs\/assets\/team-light-1x\.webp 1x, docs\/assets\/team-light\.webp 2x"/', $picture));
        self::assertFileExists($this->root().'/docs/assets/team-light.webp');
        self::assertFileExists($this->root().'/docs/assets/team-light-1x.webp');
    }

    public function testTheTeamPictureAltNamesTheOctopusAndAllEightRoles(): void
    {
        $alt = $this->imgAttribute('alt');

        self::assertStringContainsString('octopus', $alt);
        foreach (self::ROLES as $role) {
            self::assertStringContainsString($role, $alt);
        }
    }

    public function testTheTeamPictureHasAFixedWidthOfAtMost760AndAHeight(): void
    {
        $width = (int) $this->imgAttribute('width');
        $height = (int) $this->imgAttribute('height');

        self::assertGreaterThan(0, $width);
        self::assertLessThanOrEqual(760, $width);
        self::assertGreaterThan(0, $height);
    }

    public function testTheTeamPictureLoadsLazily(): void
    {
        self::assertSame('lazy', $this->imgAttribute('loading'));
    }

    // Below the five-minute start, because the first screen shows the product itself: the screenshot of the review page (#3142).
    public function testTheTeamPictureSitsAfterTheProductScreenshotAndIsFollowedByTheRolesInOrder(): void
    {
        $readme = $this->readme();

        $screenshot = strpos($readme, 'src="docs/assets/dashboard-review.png"');
        self::assertIsInt($screenshot, 'The README shows the review page on its first screen.');
        $picture = (int) strpos($readme, '<picture>', $screenshot);
        $release = (int) strpos($readme, '## The first release');
        $line = 'Tech lead, Developer, Code reviewer, Judge, Security engineer, Designer, AI engineer and Maintainer';

        self::assertGreaterThan($screenshot, $picture);
        self::assertLessThan($release, $picture);
        self::assertStringContainsString($line, substr($readme, $picture, $release - $picture));
    }

    private function picture(): string
    {
        $found = preg_match('/<picture>\s*<source media="\(prefers-color-scheme: dark\)" srcset="docs\/assets\/team-dark[^>]*>.*?<\/picture>/s', $this->readme(), $match);

        self::assertSame(1, $found);

        return $match[0];
    }

    private function imgAttribute(string $name): string
    {
        $found = preg_match('/\s'.$name.'="([^"]*)"/', $this->picture(), $match);

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
