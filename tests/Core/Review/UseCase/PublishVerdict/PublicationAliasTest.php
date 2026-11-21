<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\UseCase\PublishVerdict\ChangePublications;
use Intakt\Core\Review\UseCase\PublishVerdict\PublicationBusy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ChangePublications::class)]
#[UsesClass(ChangeAddress::class)]
#[UsesClass(PublicationBusy::class)]
final class PublicationAliasTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';

    /**
     * @return iterable<string, array{string}>
     */
    public static function oldAliases(): iterable
    {
        yield 'a fragment' => ['https://github.com/Acme/Widgets/pull/7#discussion'];
        yield 'a query' => ['https://github.com/Acme/Widgets/pull/7?tab=files'];
        yield 'a trailing slash' => ['https://github.com/Acme/Widgets/pull/7/'];
    }

    #[DataProvider('oldAliases')]
    public function testAnOldAliasOfAPullRequestAndItsCanonicalAddressShareOnePublicationLock(string $alias): void
    {
        $publications = new ChangePublications(new LockFactory(new InMemoryStore()));

        $this->expectException(PublicationBusy::class);

        $publications->whileHeld('intakt', $alias, static fn () => $publications->whileHeld('intakt', self::PULL, static fn (): null => null));
    }

    public function testTwoSpellingsOfALocalBranchStillShareOnePublicationLock(): void
    {
        $publications = new ChangePublications(new LockFactory(new InMemoryStore()));

        $this->expectException(PublicationBusy::class);

        $publications->whileHeld('intakt', 'feat/Widget', static fn () => $publications->whileHeld('intakt', 'feat/widget', static fn (): null => null));
    }

    public function testAnotherPullRequestIsPublishedWhileOneIsHeld(): void
    {
        $publications = new ChangePublications(new LockFactory(new InMemoryStore()));
        $published = false;

        $publications->whileHeld('intakt', self::PULL, static function () use ($publications, &$published): void {
            $publications->whileHeld('intakt', 'https://github.com/acme/widgets/pull/8', static function () use (&$published): void {
                $published = true;
            });
        });

        self::assertTrue($published);
    }
}
