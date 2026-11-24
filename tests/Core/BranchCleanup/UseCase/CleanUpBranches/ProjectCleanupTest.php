<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\UseCase\CleanUpBranches;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\BranchCleanup\Domain\BranchFacts;
use Intakt\Core\BranchCleanup\Domain\BranchRules;
use Intakt\Core\BranchCleanup\Domain\BranchWarning;
use Intakt\Core\BranchCleanup\Domain\CleanupPolicy;
use Intakt\Core\BranchCleanup\Domain\Judgement;
use Intakt\Core\BranchCleanup\Infrastructure\CleanupPolicies;
use Intakt\Core\BranchCleanup\Infrastructure\ProjectForges;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\BranchWarnings;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\CleanupNotRun;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\CleanupReport;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\ProjectCleanup;
use Intakt\Core\BranchCleanup\UseCase\RestoreBranch\RestoreBranch;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Core\Git\UseCase\ArchiveBranch\MirrorBranchArchive;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\BranchRestore;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalForgeBranches;
use Intakt\Tests\Core\BranchCleanup\UseCase\BranchCleanupScenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(ProjectCleanup::class)]
#[CoversClass(BranchWarnings::class)]
#[CoversClass(BranchWarning::class)]
#[CoversClass(CleanupReport::class)]
#[CoversClass(CleanupNotRun::class)]
#[CoversClass(RestoreBranch::class)]
#[CoversClass(ProjectForges::class)]
#[CoversClass(CleanupPolicies::class)]
#[UsesClass(BranchRules::class)]
#[UsesClass(CleanupPolicy::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(BranchFacts::class)]
#[UsesClass(MirrorBranchArchive::class)]
#[UsesClass(LocalForgeBranches::class)]
#[UsesClass(ArchivedBranch::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(MirroredHead::class)]
#[UsesClass(BranchChange::class)]
#[UsesClass(BranchRestore::class)]
#[UsesClass(ForgeBranch::class)]
#[UsesClass(CommitId::class)]
#[Group('persistence')]
final class ProjectCleanupTest extends KernelTestCase
{
    private BranchCleanupScenario $scenario;

    public function testAnUnclaimedBranchIsWarnedAWeekBeforeItsSixtyDaysAndNotDeletedYet(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(53);

        $report = $this->cleanUp();

        self::assertSame([['intakt/7-old', $head]], $this->warned($report));
        self::assertSame([], $report->deleted);
        self::assertSame($head, $this->scenario->headOf('intakt/7-old'));
    }

    public function testAWarnedBranchIsDeletedAWeekLaterAndItsHeadKeptAsAnArchiveRef(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(53);
        $this->cleanUp();
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame(['intakt/7-old'], array_map(static fn (ArchivedBranch $archived): string => $archived->branch, $report->deleted));
        self::assertSame('', $this->scenario->headOf('intakt/7-old'));
        self::assertSame($head, $this->scenario->mirrorGit('rev-parse', $report->deleted[0]->ref));
        self::assertStringStartsWith('refs/intakt/archive/', $report->deleted[0]->ref);
    }

    public function testAWarnedBranchIsNotDeletedSixDaysAfterItsWarning(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(54);
        $this->cleanUp();
        $this->scenario->daysLater(6);

        $report = $this->cleanUp();

        self::assertSame([], $report->deleted);
        self::assertSame([['intakt/7-old', $head]], $this->warned($report));
        self::assertSame($head, $this->scenario->headOf('intakt/7-old'));
    }

    public function testADueBranchNeverWarnedIsWarnedInsteadOfDeleted(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(90);

        $report = $this->cleanUp();

        self::assertSame([], $report->deleted);
        self::assertSame([['intakt/7-old', $head]], $this->warned($report));
    }

    public function testABranchThatMovedAfterItsWarningIsWarnedAgainAtItsNewHeadInsteadOfDeleted(): void
    {
        $this->scenario->intaktBranch('intakt/7-old');
        $moved = $this->scenario->intaktBranch('intakt/7-later', 'c.txt');
        $this->scenario->daysLater(53);
        $this->cleanUp();
        $this->scenario->git('update-ref', 'refs/heads/intakt/7-old', $moved);
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame(['intakt/7-later'], array_map(static fn (ArchivedBranch $archived): string => $archived->branch, $report->deleted));
        self::assertSame([['intakt/7-old', $moved]], $this->warned($report));
        self::assertSame($moved, $this->scenario->headOf('intakt/7-old'));
    }

    public function testABranchWithoutAPullRequestIsListedStaleAtThirtyDays(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-quiet');
        $this->scenario->daysLater(31);

        $report = $this->cleanUp();

        self::assertEquals([new BranchHead('intakt/7-quiet', $head)], $report->stale);
        self::assertSame([], $report->warned);
    }

    public function testABranchAPersonCommittedToIsNeverDeleted(): void
    {
        $head = $this->scenario->personBranch('intakt/7-taken');
        $this->scenario->daysLater(61);
        $this->cleanUp();
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame([], $report->deleted);
        self::assertSame([], $report->warned);
        self::assertEquals([new BranchHead('intakt/7-taken', $head)], $report->stale);
        self::assertSame($head, $this->scenario->headOf('intakt/7-taken'));
    }

    public function testABranchNamedInKeepBranchesIsNeverDeleted(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-kept');
        $this->scenario->policies = [BranchCleanupScenario::PROJECT => ['clean_other_branches' => false, 'keep_branches' => ['intakt/7-kept']]];
        $this->scenario->daysLater(61);
        $this->cleanUp();
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame([], $report->deleted);
        self::assertSame([], $report->warned);
        self::assertSame($head, $this->scenario->headOf('intakt/7-kept'));
    }

    public function testABranchWithoutIntaktsPrefixIsLeftAloneByDefault(): void
    {
        $head = $this->scenario->intaktBranch('feature/old');
        $this->scenario->daysLater(61);

        $report = $this->cleanUp();

        self::assertSame([], $report->warned);
        self::assertSame([], $report->stale);
        self::assertSame($head, $this->scenario->headOf('feature/old'));
    }

    public function testABranchWithoutIntaktsPrefixIsWarnedOnceTheProjectOptsIn(): void
    {
        $head = $this->scenario->intaktBranch('feature/old');
        $this->scenario->policies = [BranchCleanupScenario::PROJECT => ['clean_other_branches' => true, 'keep_branches' => []]];
        $this->scenario->daysLater(61);

        self::assertSame([['feature/old', $head]], $this->warned($this->cleanUp()));
    }

    public function testTheDefaultAndAReleaseBranchAreNeverDeletedEvenWithTheOptIn(): void
    {
        $this->scenario->intaktBranch('release/1.0');
        $this->scenario->policies = [BranchCleanupScenario::PROJECT => ['clean_other_branches' => true, 'keep_branches' => []]];
        $this->scenario->daysLater(61);
        $this->cleanUp();
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame([], $report->warned);
        self::assertSame([], $report->deleted);
        self::assertSame([], $report->stale);
        self::assertNotSame('', $this->scenario->headOf('main'));
        self::assertNotSame('', $this->scenario->headOf('release/1.0'));
    }

    public function testAMergedBranchIsWarnedAndDeletedOnTheRunAWeekLater(): void
    {
        $this->scenario->intaktBranch('intakt/7-merged');
        $this->scenario->changes['intakt/7-merged'] = new BranchChange(BranchChangeState::Merged, $this->scenario->clock->now(), []);
        $this->cleanUp();
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame(['intakt/7-merged'], array_map(static fn (ArchivedBranch $archived): string => $archived->branch, $report->deleted));
        self::assertSame('', $this->scenario->headOf('intakt/7-merged'));
    }

    public function testAClosedUnmergedBranchIsKeptForThirtyDaysThenDeletedAfterItsWarning(): void
    {
        $this->scenario->intaktBranch('intakt/7-closed');
        $this->scenario->changes['intakt/7-closed'] = new BranchChange(BranchChangeState::ClosedUnmerged, $this->scenario->clock->now(), []);
        $this->scenario->daysLater(22);
        $early = $this->cleanUp();
        $this->scenario->daysLater(1);
        $this->cleanUp();
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame([], $early->warned);
        self::assertSame(['intakt/7-closed'], array_map(static fn (ArchivedBranch $archived): string => $archived->branch, $report->deleted));
    }

    public function testAClosedUnmergedBranchWhosePullRequestIsLabeledKeepIsNeverDeleted(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-closed');
        $this->scenario->changes['intakt/7-closed'] = new BranchChange(BranchChangeState::ClosedUnmerged, $this->scenario->clock->now(), ['keep']);
        $this->scenario->daysLater(90);
        $this->cleanUp();
        $this->scenario->daysLater(7);

        $report = $this->cleanUp();

        self::assertSame([], $report->warned);
        self::assertSame([], $report->deleted);
        self::assertSame($head, $this->scenario->headOf('intakt/7-closed'));
    }

    public function testADeletedBranchIsRestoredFromItsArchiveRef(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(53);
        $this->cleanUp();
        $this->scenario->daysLater(7);
        $deleted = $this->cleanUp()->deleted[0];

        $restored = $this->scenario->restore()->restore(BranchCleanupScenario::PROJECT, 'intakt/7-old');

        self::assertEquals($deleted, $restored);
        self::assertSame($head, $this->scenario->headOf('intakt/7-old'));
    }

    public function testABranchNeverArchivedCannotBeRestored(): void
    {
        $this->expectException(BranchNotArchived::class);

        $this->scenario->restore()->restore(BranchCleanupScenario::PROJECT, 'intakt/7-never');
    }

    public function testARunWhileAnotherRunOfTheProjectHoldsItsLockIsRefused(): void
    {
        $lock = $this->scenario->locks->createLock('branch-cleanup-'.BranchCleanupScenario::PROJECT);
        $lock->acquire();

        $this->expectExceptionObject(CleanupNotRun::alreadyRunning(BranchCleanupScenario::PROJECT));

        $this->cleanUp();
    }

    public function testAWarningIsKeptWithTheMomentItWasFirstGiven(): void
    {
        $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(53);
        $first = $this->scenario->clock->now();
        $this->cleanUp();
        $this->scenario->daysLater(3);

        $report = $this->cleanUp();

        self::assertEquals($first, $report->warned[0]->warnedAt());
    }

    protected function setUp(): void
    {
        $manager = $this->manager();
        $tool = new SchemaTool($manager);
        $tool->dropDatabase();
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $this->scenario = new BranchCleanupScenario($manager);
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
        parent::tearDown();
    }

    private function cleanUp(): CleanupReport
    {
        $report = $this->scenario->cleanup()->run(BranchCleanupScenario::PROJECT);
        $this->manager()->clear();

        return $report;
    }

    /**
     * @return list<array{string, string}>
     */
    private function warned(CleanupReport $report): array
    {
        return array_map(static fn (BranchWarning $warning): array => [$warning->branch(), $warning->head()], $report->warned);
    }

    private function manager(): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
