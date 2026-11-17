<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\MascotPose;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversNothing]
final class EmptyTemplateTest extends KernelTestCase
{
    private const array STATE = ['title' => 'No reviews yet', 'text' => 'No review has started yet.', 'action' => ['href' => '/setup', 'label' => 'Set up Intakt']];

    public function testAnEmptyStateWithAPoseShowsThatOctopusAt144PxAsADecorativeImageInBothThemes(): void
    {
        $images = $this->rendered([...self::STATE, 'pose' => MascotPose::Waiting])->filter('.empty > img.character');

        self::assertSame(['', ''], $images->each(static fn (Crawler $image): ?string => $image->attr('alt')));
        self::assertSame([null, null], $images->each(static fn (Crawler $image): ?string => $image->attr('role')));
        self::assertSame([['144', '144', 'lazy'], ['144', '144', 'lazy']], $images->each(static fn (Crawler $image): array => [$image->attr('width'), $image->attr('height'), $image->attr('loading')]));
        self::assertSame(1, preg_match('~octopus-waiting-light[^"]*\.webp$~', $images->eq(0)->attr('src') ?? ''));
        self::assertSame(1, preg_match('~octopus-waiting-dark[^"]*\.webp$~', $images->eq(1)->attr('src') ?? ''));
    }

    public function testAnEmptyStateWithAPoseKeepsItsTitleTextAndOneAction(): void
    {
        $plain = $this->rendered(self::STATE)->filter('.empty');
        $posed = $this->rendered([...self::STATE, 'pose' => MascotPose::Waiting])->filter('.empty');

        self::assertSame($plain->text(), $posed->text());
        self::assertSame($this->texts($plain), $this->texts($posed));
        self::assertSame(1, $posed->filter('a')->count());
    }

    public function testAnEmptyStateWithoutAPoseShowsNoImage(): void
    {
        self::assertSame(0, $this->rendered(self::STATE)->filter('img')->count());
    }

    /** @return list<string> */
    private function texts(Crawler $empty): array
    {
        return $empty->filter('h2, p, a')->each(static fn (Crawler $element): string => $element->outerHtml());
    }

    /** @param array<string, mixed> $state */
    private function rendered(array $state): Crawler
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler($twig->render('dashboard/components/empty.html.twig', $state));
    }
}
