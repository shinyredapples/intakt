<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

// Stored rows are added out of id order on purpose, because an engine returns rows without an order in whatever order it keeps them, and PostgreSQL moves a row it updates.
#[Group('persistence')]
#[CoversClass(ReviewRound::class)]
#[CoversClass(RoundReviewer::class)]
#[UsesClass(ReviewTarget::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(ChangeRound::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
final class ReviewRoundReviewerOrderTest extends KernelTestCase
{
    private EntityManagerInterface $entities;

    public function testAStoredRoundListsItsReviewersInTheOrderOfTheirIds(): void
    {
        $first = new UuidV7('01a1b000-0000-7000-8000-000000000001');
        $second = new UuidV7('01a1b000-0000-7000-8000-000000000002');
        $round = new ReviewRound(Uuid::v7(), new ReviewTarget(new ReviewSubject('alpha', __DIR__, 'Add the thing.', 'diff --git a/a b/a'), SampleIdentity::any()), new ChangeRound('feat/thing', 1, '[]'));
        $round->addReviewer($second, $this->member('role-2'));
        $round->addReviewer($first, $this->member('role-1'));
        $this->entities->persist($round);
        $this->entities->flush();
        $this->entities->clear();

        $stored = $this->entities->find(ReviewRound::class, $round->id()) ?? self::fail('the round was not stored');

        self::assertSame([$first->toRfc4122(), $second->toRfc4122()], array_map(static fn (RoundReviewer $reviewer): string => $reviewer->id()->toRfc4122(), $stored->reviewers()));
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        $entities = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;

        EmptyDatabase::rowsIn($container);
    }

    /**
     * @param non-empty-string $role
     */
    private function member(string $role): PanelReviewer
    {
        return new PanelReviewer(new ReviewerRole($role, 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1);
    }
}
