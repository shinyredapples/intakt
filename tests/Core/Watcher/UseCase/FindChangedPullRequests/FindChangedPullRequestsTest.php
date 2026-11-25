<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Core\Watcher\Domain\ListingValidator;
use Intakt\Core\Watcher\Domain\SeenChange;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\Forge\Contract\OpenChanges;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(FindChangedPullRequests::class)]
#[CoversClass(SeenListings::class)]
#[CoversClass(SeenChange::class)]
#[CoversClass(ListingValidator::class)]
#[CoversClass(ListingInProgress::class)]
#[UsesClass(OpenChangeListing::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
final class FindChangedPullRequestsTest extends KernelTestCase
{
    private RecordedForge $forge;
    private EntityManagerInterface $entities;
    private LockFactory $locks;
    private RecordingLogger $log;

    public function testAPullRequestSeenForTheFirstTimeIsReturned(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));

        $changed = $this->find('alpha');

        self::assertSame(['7'], $this->ids($changed));
    }

    public function testAnUnchangedListingReadsNothingFurtherAndKeepsTheStoredValidator(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'), RecordedForge::change('8', 'b', '2026-11-01T11:00:00Z'));
        $first = $this->find('alpha');

        $second = $this->find('alpha');

        self::assertSame(['7', '8'], $this->ids($first));
        self::assertSame([], $second);
        self::assertSame(1, $this->forge->fullReads);
        self::assertSame(1, $this->forge->conditionalAnswers);
        self::assertSame([null, 'W/"alpha-1"'], $this->forge->received);
        self::assertSame('W/"alpha-1"', $this->entities->find(ListingValidator::class, 'alpha')?->value());
        self::assertSame(['Listed the open pull requests.', 'Listed the open pull requests: unchanged.'], array_column($this->log->logged, 0));
        self::assertSame([['status' => 304, 'changed' => 0]], [array_intersect_key($this->log->logged[1][1], ['status' => 1, 'changed' => 1])]);
    }

    public function testTheOpenPullRequestsOfAProjectAreStillNamedAfterAnUnchangedListing(): void
    {
        $fractional = $this->at('8', '2026-11-01 11:00:00.123456');
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'), $fractional);
        $this->forge->opens('beta', RecordedForge::change('9', 'c', '2026-11-01T10:00:00Z'));
        $this->find('alpha');
        $this->find('beta');
        $this->find('alpha');
        $this->entities->clear();

        $open = $this->use($this->forge)->openOf('alpha');

        self::assertSame(1, $this->forge->conditionalAnswers);
        self::assertEquals([RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'), $fractional], $open);
    }

    public function testAPullRequestWhoseHeadAndUpdateTimeAreUnchangedIsNotReturnedAgain(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->find('alpha');
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'), RecordedForge::change('9', 'c', '2026-11-01T12:00:00Z'));

        $changed = $this->find('alpha');

        self::assertSame(['9'], $this->ids($changed));
    }

    public function testAPullRequestWhoseHeadMovedIsReturned(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->find('alpha');
        $this->forge->opens('alpha', RecordedForge::change('7', 'b', '2026-11-01T10:00:00Z'));

        self::assertSame(['7'], $this->ids($this->find('alpha')));
    }

    public function testAPullRequestWhoseUpdateTimeChangedIsReturned(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->find('alpha');
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:05:00Z'));

        self::assertSame(['7'], $this->ids($this->find('alpha')));
    }

    public function testAPullRequestClosedAndOpenedAgainIsReturnedAsNew(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->find('alpha');
        $this->forge->opens('alpha');
        $this->find('alpha');
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));

        self::assertSame(['7'], $this->ids($this->find('alpha')));
    }

    public function testTheValidatorOfOneProjectNeverAffectsAnother(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->forge->opens('beta', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->find('alpha');

        $changed = $this->find('beta');

        self::assertSame(['7'], $this->ids($changed));
        self::assertSame([null, null], $this->forge->received);
    }

    public function testAFailedReadKeepsWhatWasSeenSoTheNextListingIsStillFull(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));

        try {
            $this->use(new FailingForge())('alpha');
            self::fail('The read should fail.');
        } catch (ForgeReadFailed) {
        }

        self::assertSame(['7'], $this->ids($this->find('alpha')));
    }

    public function testAListingOfAProjectIsRefusedWhileAnotherOneRuns(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $held = $this->locks->createLock('watcher-listing-'.hash('sha256', 'alpha'));
        self::assertTrue($held->acquire());

        $this->expectException(ListingInProgress::class);

        try {
            $this->find('alpha');
        } finally {
            self::assertSame(0, $this->forge->fullReads);
        }
    }

    public function testTheLockIsReleasedAfterAListing(): void
    {
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->find('alpha');

        $this->forge->opens('alpha', RecordedForge::change('7', 'b', '2026-11-01T10:00:00Z'));

        self::assertSame(['7'], $this->ids($this->find('alpha')));
    }

    public function testAnUnchangedPullRequestWithAFractionalUpdateTimeIsNotReturnedAgainAfterReloading(): void
    {
        $fractional = $this->at('7', '2026-11-01 10:00:00.123456');
        $this->forge->opens('alpha', $fractional);
        $this->find('alpha');
        $this->entities->clear();
        $this->forge->opens('alpha', $fractional, RecordedForge::change('9', 'c', '2026-11-01T12:00:00Z'));

        self::assertSame(['9'], $this->ids($this->find('alpha')));
    }

    public function testAListingSeesWhatAnotherWorkerStoredSinceItsOwnLastListing(): void
    {
        $other = new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration());
        $this->forge->opens('alpha', RecordedForge::change('7', 'a', '2026-11-01T10:00:00Z'));
        $this->find('alpha');
        $this->forge->opens('alpha', RecordedForge::change('7', 'b', '2026-11-01T10:00:00Z'));
        $this->use($this->forge, $other)('alpha');
        $this->forge->opens('alpha', RecordedForge::change('7', 'b', '2026-11-01T10:00:00Z'));

        self::assertSame([], $this->find('alpha'));
    }

    public function testAnUpdateWithinTheSameSecondIsReturned(): void
    {
        $this->forge->opens('alpha', $this->at('7', '2026-11-01 10:00:00.123456'));
        $this->find('alpha');
        $this->entities->clear();
        $this->forge->opens('alpha', $this->at('7', '2026-11-01 10:00:00.654321'));

        self::assertSame(['7'], $this->ids($this->find('alpha')));
    }

    public function testAPendingWriteOfTheCallerSurvivesAListing(): void
    {
        $this->entities->persist(new ListingValidator('beta', 'pending'));

        new SeenListings($this->entities, $this->locks)->whileListing('alpha', static fn (): null => null);

        $this->entities->flush();
        $this->entities->clear();
        self::assertSame('pending', $this->entities->find(ListingValidator::class, 'beta')?->value());
    }

    public function testAChangeToAnEntityTheCallerHoldsAcrossAListingIsFlushed(): void
    {
        $beta = new ListingValidator('beta', 'old');
        $this->entities->persist($beta);
        $this->entities->flush();

        new SeenListings($this->entities, $this->locks)->whileListing('alpha', static fn (): null => null);

        $beta->replace('new');
        $this->entities->flush();
        $this->entities->clear();
        self::assertSame('new', $this->entities->find(ListingValidator::class, 'beta')?->value());
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::rowsIn(self::getContainer());
        $this->forge = new RecordedForge();
        $this->log = new RecordingLogger();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    private function at(string $id, string $updatedAt): OpenChange
    {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $updatedAt, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $moment);

        return new OpenChange(new ChangeId($id), new CommitId(str_repeat('a', 40)), $moment);
    }

    /**
     * @return list<OpenChange>
     */
    private function find(string $project): array
    {
        return $this->use($this->forge)($project);
    }

    private function use(OpenChanges $forge, ?EntityManagerInterface $entities = null): FindChangedPullRequests
    {
        return new FindChangedPullRequests($forge, new SeenListings($entities ?? $this->entities, $this->locks), $this->log);
    }

    /**
     * @param list<OpenChange> $changes
     *
     * @return list<string>
     */
    private function ids(array $changes): array
    {
        return array_map(static fn (OpenChange $change): string => $change->id->value, $changes);
    }
}
