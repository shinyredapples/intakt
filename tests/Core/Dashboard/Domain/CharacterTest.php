<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\Character;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Character::class)]
final class CharacterTest extends TestCase
{
    private const string ROLES = __DIR__.'/../../../../assets/images/roles/';

    /**
     * @return iterable<string, array{Character}>
     */
    public static function characters(): iterable
    {
        foreach (Character::cases() as $character) {
            yield $character->value => [$character];
        }
    }

    #[DataProvider('characters')]
    public function testEachCharacterHasItsSixImagesOnDisk(Character $character): void
    {
        foreach (['light', 'dark'] as $theme) {
            foreach ([48, 96, 192] as $pixels) {
                $file = sprintf('%s%s-%s-%d.webp', self::ROLES, $character->value, $theme, $pixels);
                self::assertFileExists($file);
                self::assertSame([$pixels, $pixels], array_slice((array) getimagesize($file), 0, 2), $file);
            }
        }
    }

    public function testTheCodeReviewerIsAMeerkatWithAMagnifyingGlass(): void
    {
        self::assertSame('code_reviewer', Character::CodeReviewer->value);
        self::assertSame('Code reviewer', Character::CodeReviewer->label());
        self::assertSame('Meerkat with a magnifying glass', Character::CodeReviewer->description());
    }

    public function testTheJudgeIsAnOwlWithAGavel(): void
    {
        self::assertSame('judge', Character::Judge->value);
        self::assertSame('Judge', Character::Judge->label());
        self::assertSame('Owl with a gavel', Character::Judge->description());
    }

    public function testTheDeveloperIsABeaverWithAWrench(): void
    {
        self::assertSame('developer', Character::Developer->value);
        self::assertSame('Developer', Character::Developer->label());
        self::assertSame('Beaver with a wrench', Character::Developer->description());
    }
}
