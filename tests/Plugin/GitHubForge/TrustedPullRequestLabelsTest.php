<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Domain\LabelFamilies;
use Intakt\Plugin\GitHubForge\Domain\SteeringTrust;
use Intakt\Plugin\GitHubForge\Domain\TrustedLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustedPullRequestLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrustedPullRequestLabels::class)]
#[UsesClass(LabelFamilies::class)]
#[UsesClass(LabelApplication::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(IssueLabels::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(SteeringTrust::class)]
#[UsesClass(TrustedLabels::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(Paths::class)]
final class TrustedPullRequestLabelsTest extends TestCase
{
    public function testReviewFullAppliedByAWriterPicksTheFullPanel(): void
    {
        $labels = $this->labelsOf([new LabelApplication('review:full', RecordedTrust::WRITER)]);

        self::assertTrue(new ChangeDescription(3, new Paths(), $labels)->wantsFullReview());
    }

    public function testAnAccountWithoutWriteAccessSteersNothing(): void
    {
        $labels = $this->labelsOf([new LabelApplication('review:full', RecordedTrust::TRIAGE), new LabelApplication('model:strong', RecordedTrust::TRIAGE)]);

        self::assertFalse(new ChangeDescription(3, new Paths(), $labels)->wantsFullReview());
        self::assertNull(BuildFacts::fromLabels($labels, new Paths())->labelledTier);
    }

    public function testAForeignBotSteersNothingWhateverItsPermission(): void
    {
        self::assertFalse($this->labelsOf([new LabelApplication('review:full', RecordedTrust::BOT_BY_TYPE)])->contains('review:full'));
    }

    public function testModelAppliedByAWriterSetsTheStartTier(): void
    {
        $labels = $this->labelsOf([new LabelApplication('model:strong', RecordedTrust::WRITER)]);

        self::assertSame(Tier::Strong, BuildFacts::fromLabels($labels, new Paths())->labelledTier);
    }

    public function testTwoModelLabelsResolveToTheOneAppliedLast(): void
    {
        $labels = $this->labelsOf([new LabelApplication('model:light', RecordedTrust::WRITER), new LabelApplication('model:strong', RecordedTrust::WRITER)]);

        self::assertSame(Tier::Strong, BuildFacts::fromLabels($labels, new Paths())->labelledTier);
    }

    public function testALabelNoLongerOnThePullRequestIsIgnored(): void
    {
        $labels = $this->labelsOf([new LabelApplication('review:full', RecordedTrust::WRITER)], []);

        self::assertFalse($labels->contains('review:full'));
    }

    public function testATrustedLabelAfterAnUntrustedOneOfTheSameFamilyWins(): void
    {
        $labels = $this->labelsOf([new LabelApplication('model:light', RecordedTrust::TRIAGE), new LabelApplication('model:standard', RecordedTrust::WRITER)]);

        self::assertSame(Tier::Standard, BuildFacts::fromLabels($labels, new Paths())->labelledTier);
    }

    public function testTheValueAppliedEarlierInAFamilyIsNamedForRemoval(): void
    {
        $trusted = $this->trustedOf([new LabelApplication('model:light', RecordedTrust::WRITER), new LabelApplication('review:full', RecordedTrust::WRITER), new LabelApplication('model:strong', RecordedTrust::WRITER)]);

        self::assertSame(['model:light'], $trusted->outvoted);
    }

    public function testAnUntrustedValueInAFamilyIsNotNamedForRemoval(): void
    {
        $trusted = $this->trustedOf([new LabelApplication('model:light', RecordedTrust::TRIAGE), new LabelApplication('model:strong', RecordedTrust::WRITER)]);

        self::assertSame([], $trusted->outvoted);
    }

    /**
     * @param list<LabelApplication> $applied
     * @param ?list<string>          $current
     */
    private function labelsOf(array $applied, ?array $current = null): Labels
    {
        return $this->trustedOf($applied, $current)->steering;
    }

    /**
     * @param list<LabelApplication> $applied
     * @param ?list<string>          $current
     */
    private function trustedOf(array $applied, ?array $current = null): TrustedLabels
    {
        $names = $current ?? array_map(static fn (LabelApplication $application): string => $application->label, $applied);
        $histories = self::createStub(IssueHistories::class);
        $histories->method('of')->willReturn(new IssueHistory('alice', $applied, '', new IssueLabels(...$names)));
        $trust = new RecordedTrust();

        return new TrustedPullRequestLabels($histories, TrustChecks::over($trust), new WatchedRepositories(['alpha' => ['github' => 'acme/widget']]))->of('alpha', 1, new DateTimeImmutable('2026-11-25 10:00:00'));
    }
}
