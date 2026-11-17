<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because the team picture is the first thing a visitor sees, and a wrong source or a missing size breaks the page.
#[CoversNothing]
final class ReadmeTeamPictureTest extends TestCase
{
    private const ROLES = ['Tech lead', 'Developer', 'Code reviewer', 'Judge', 'Security engineer', 'Designer', 'AI engineer', 'Maintainer'];

    public function testTheTeamPictureServesTheDarkImageForADarkColorScheme(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<source media="\(prefers-color-scheme: dark\)" srcset="docs\/assets\/team-dark\.webp 2x">/', $picture));
        self::assertFileExists($this->root().'/docs/assets/team-dark.webp');
    }

    public function testTheTeamPictureServesTheLightImageOtherwise(): void
    {
        $picture = $this->picture();

        self::assertSame(1, preg_match('/<img src="docs\/assets\/team-light\.webp" srcset="docs\/assets\/team-light\.webp 2x"/', $picture));
        self::assertFileExists($this->root().'/docs/assets/team-light.webp');
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

    public function testTheTeamPictureSitsUnderTheOpeningAndIsFollowedByTheRolesInOrder(): void
    {
        $readme = $this->readme();

        $opening = (int) strpos($readme, 'Intakt gives you one verdict');
        $picture = (int) strpos($readme, '<picture>', $opening);
        $links = (int) strpos($readme, 'PHP 8.5, Symfony AI');
        $line = 'Tech lead, Developer, Code reviewer, Judge, Security engineer, Designer, AI engineer and Maintainer';

        self::assertGreaterThan($opening, $picture);
        self::assertLessThan($links, $picture);
        self::assertStringContainsString($line, substr($readme, $picture, $links - $picture));
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
