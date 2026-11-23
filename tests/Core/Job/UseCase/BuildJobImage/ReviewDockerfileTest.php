<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\CliManifest;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\JobTools;
use Intakt\Core\Job\UseCase\BuildJobImage\ReviewDockerfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewDockerfile::class)]
#[CoversClass(JobTools::class)]
#[UsesClass(CliManifest::class)]
#[UsesClass(JobImageNotBuilt::class)]
final class ReviewDockerfileTest extends TestCase
{
    public function testTheJobToolsFollowTheProjectsLastStageAsRoot(): void
    {
        $dockerfile = ReviewDockerfile::after("FROM php:8.5-cli AS base\nFROM base\nUSER app\n");

        self::assertStringStartsWith("FROM php:8.5-cli AS base\nFROM base\nUSER app\n\n# Added by Intakt for a review job.\nUSER root\nRUN apt-get update \\\n", $dockerfile);
        self::assertStringEndsWith(implode("\n", JobTools::steps())."\n", $dockerfile);
    }

    public function testTheCliToolsAreInstalledAtTheVersionsOfTheCliManifest(): void
    {
        $dockerfile = ReviewDockerfile::after("FROM php:8.5-cli\n");

        self::assertStringContainsString(sprintf('RUN npm install --global %s \\', implode(' ', CliManifest::installArgumentsFrom(CliManifest::PATH))), $dockerfile);
    }

    public function testADockerfileWithoutAFinalLineBreakIsExtendedOnALineOfItsOwn(): void
    {
        self::assertStringStartsWith("FROM php:8.5-cli\n\n# Added by Intakt", ReviewDockerfile::after('FROM php:8.5-cli'));
    }

    #[DataProvider('extendable')]
    public function testADockerfileWhoseLinesStillEndWithABackslashIsExtended(string $dockerfile): void
    {
        self::assertStringContainsString("USER root\n", ReviewDockerfile::after($dockerfile));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function extendable(): iterable
    {
        yield 'a syntax directive' => ["# syntax=docker/dockerfile:1\nFROM php:8.5-cli\n"];
        yield 'the backslash as escape' => ["# escape=\\\nFROM php:8.5-cli\n"];
        yield 'a directive whose value holds spaces' => ["# check=skip=all; error=true\nFROM php:8.5-cli\n"];
        yield 'an escape line after a comment with a key and spaces, which is a comment' => ["# maintainer=Example Team\n# escape=`\nFROM php:8.5-cli\n"];
        yield 'an escape line after an unknown directive name, which is a comment' => ["# syntax2=docker/dockerfile:1\n# escape=`\nFROM php:8.5-cli\n"];
        yield 'an escape line after an instruction, which is a comment' => ["FROM php:8.5-cli\n# escape=`\n"];
        yield 'an escape line after a comment, which is a comment' => ["# The job image\n# escape=`\nFROM php:8.5-cli\n"];
        yield 'an escape line after a blank line, which is a comment' => ["# syntax=docker/dockerfile:1\n\n# escape=`\nFROM php:8.5-cli\n"];
        yield 'a closed continuation' => ["FROM php:8.5-cli\nRUN apt-get update \\\n    && apt-get clean\n"];
        yield 'a last comment ending in a backslash' => ["FROM php:8.5-cli\n# C:\\\n"];
        yield 'an escaped backslash at the end, which Docker keeps as a literal' => ["FROM debian:bookworm\nRUN echo literal \\\\\n"];
        yield 'an escaped backslash before trailing spaces' => ["FROM debian:bookworm\nRUN echo literal \\\\  \n"];
        yield 'Windows line ends' => ["FROM php:8.5-cli\r\nRUN true\r\n"];
    }

    #[DataProvider('anotherEscape')]
    public function testADockerfileWithAnotherEscapeCharacterIsRefused(string $dockerfile, string $escape): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notExtendable(sprintf('it sets the escape character to "%s"', $escape)));

        ReviewDockerfile::after($dockerfile);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function anotherEscape(): iterable
    {
        yield 'first line' => ["# escape=`\nFROM mcr.microsoft.com/windows/servercore\n", '`'];
        yield 'after another directive' => ["# syntax=docker/dockerfile:1\n#ESCAPE = `\nFROM php:8.5-cli\n", '`'];
        yield 'after a directive whose value holds spaces' => ["# check=skip=all; error=true\n# escape=`\nFROM php:8.5-cli\n", '`'];
        yield 'Windows line ends' => ["# escape=`\r\nFROM php:8.5-cli\r\n", '`'];
        yield 'after a byte order mark, which Docker strips' => ["\u{FEFF}# escape=`\nFROM php:8.5-cli\n", '`'];
    }

    #[DataProvider('openContinuation')]
    public function testADockerfileWhoseLastInstructionContinuesIsRefused(string $dockerfile): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notExtendable('its last line continues on a next line'));

        ReviewDockerfile::after($dockerfile);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openContinuation(): iterable
    {
        yield 'at the end' => ["FROM php:8.5-cli\nRUN true \\\n"];
        yield 'before blank lines' => ["FROM php:8.5-cli\nRUN true \\\n\n\n"];
        yield 'before a comment' => ["FROM php:8.5-cli\nRUN true \\\n    # a comment\n"];
        yield 'with trailing spaces' => ["FROM php:8.5-cli\nRUN true \\  \n"];
        yield 'a backslash alone on the line' => ["FROM php:8.5-cli\nRUN true \\\n\\\n"];
        yield 'an escaped backslash and then one that continues' => ["FROM php:8.5-cli\nRUN echo \\\\ \\\n"];
    }
}
