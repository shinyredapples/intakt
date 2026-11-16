<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\MascotPose;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversNothing]
final class CharacterTemplateTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{Character|MascotPose, int}>
     */
    public static function figures(): iterable
    {
        foreach (Character::cases() as $character) {
            yield $character->value.' at 24' => [$character, 24];
            yield $character->value.' at 48' => [$character, 48];
        }
        foreach (MascotPose::cases() as $pose) {
            yield 'octopus '.$pose->value => [$pose, 96];
        }
    }

    #[DataProvider('figures')]
    public function testTheCharacterIsOneLightAndOneDarkDecorativeImageOfAFixedSize(Character|MascotPose $character, int $size): void
    {
        $images = $this->rendered($character, $size)->filter('img');

        self::assertSame(['character character-light', 'character character-dark'], $images->each(static fn (Crawler $image): ?string => $image->attr('class')));
        $images->each(static function (Crawler $image) use ($size): void {
            self::assertSame('', $image->attr('alt'));
            self::assertNull($image->attr('role'));
            self::assertNull($image->attr('title'));
            self::assertSame((string) $size, $image->attr('width'));
            self::assertSame((string) $size, $image->attr('height'));
            self::assertSame('lazy', $image->attr('loading'));
        });
    }

    public function testA24PxCharacterOffersIts48And96PxFilesAs1xAnd2x(): void
    {
        $images = $this->rendered(Character::Judge, 24)->filter('img');

        self::assertMatchesRegularExpression('#^/assets/images/roles/judge-light-48-[\w-]+\.webp$#', (string) $images->first()->attr('src'));
        self::assertMatchesRegularExpression('#^/assets/images/roles/judge-light-48-[\w-]+\.webp 1x, /assets/images/roles/judge-light-96-[\w-]+\.webp 2x$#', (string) $images->first()->attr('srcset'));
        self::assertMatchesRegularExpression('#^/assets/images/roles/judge-dark-48-[\w-]+\.webp 1x, /assets/images/roles/judge-dark-96-[\w-]+\.webp 2x$#', (string) $images->last()->attr('srcset'));
    }

    public function testA48PxCharacterOffersIts96And192PxFilesAs1xAnd2x(): void
    {
        $images = $this->rendered(Character::CodeReviewer, 48)->filter('img');

        self::assertMatchesRegularExpression('#^/assets/images/roles/code_reviewer-light-96-[\w-]+\.webp 1x, /assets/images/roles/code_reviewer-light-192-[\w-]+\.webp 2x$#', (string) $images->first()->attr('srcset'));
        self::assertMatchesRegularExpression('#^/assets/images/roles/code_reviewer-dark-96-[\w-]+\.webp 1x, /assets/images/roles/code_reviewer-dark-192-[\w-]+\.webp 2x$#', (string) $images->last()->attr('srcset'));
    }

    public function testTheOctopusShowsItsPoseInEachTheme(): void
    {
        $images = $this->rendered(MascotPose::Bowing, 96)->filter('img');

        self::assertMatchesRegularExpression('#^/assets/images/mascot/octopus-bowing-light-[\w-]+\.webp$#', (string) $images->first()->attr('src'));
        self::assertMatchesRegularExpression('#^/assets/images/mascot/octopus-bowing-dark-[\w-]+\.webp$#', (string) $images->last()->attr('src'));
    }

    private function rendered(Character|MascotPose $character, int $size): Crawler
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler($twig->render('dashboard/components/character.html.twig', ['character' => $character, 'size' => $size]));
    }
}
