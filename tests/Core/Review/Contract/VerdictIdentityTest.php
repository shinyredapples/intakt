<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RecordedIdentityMalformed;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTermMalformed;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerdictIdentity::class)]
#[CoversClass(VerdictStanding::class)]
#[CoversClass(ReviewTerms::class)]
#[UsesClass(Paths::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Decision::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(RecordedIdentityMalformed::class)]
#[UsesClass(ReviewTermMalformed::class)]
#[UsesClass(CommitIdMalformed::class)]
final class VerdictIdentityTest extends TestCase
{
    private const string REVIEWED_HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string NEW_HEAD = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string NEW_BASE = 'dddddddddddddddddddddddddddddddddddddddd';

    public function testAVerdictWhoseIdentityIsUnchangedIsCurrent(): void
    {
        $standing = $this->reviewed()->standingAt($this->reviewed(), $this->move(HeadMoveKind::Unmoved));

        self::assertSame(Standing::Current, $standing->standing);
        self::assertSame([], $standing->changed);
    }

    public function testANewCommitOnTheHeadMakesTheVerdictStale(): void
    {
        $current = $this->identity(head: self::NEW_HEAD);

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Commits, 'src/Feature.php'));

        self::assertSame(Standing::Stale, $standing->standing);
        self::assertSame([IdentityField::Head], $standing->changed);
    }

    public function testANewBaseMakesTheVerdictStale(): void
    {
        $current = $this->identity(base: self::NEW_BASE);

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Unmoved));

        self::assertSame(Standing::Stale, $standing->standing);
        self::assertSame([IdentityField::Base], $standing->changed);
    }

    public function testANewTaskRevisionMakesTheVerdictStale(): void
    {
        $current = $this->identity(terms: $this->terms(task: 'Add the thing, and the other thing.'));

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Unmoved));

        self::assertSame(Standing::Stale, $standing->standing);
        self::assertSame([IdentityField::TaskRevision], $standing->changed);
    }

    public function testANewCheckCommandMakesTheVerdictStale(): void
    {
        $current = $this->identity(terms: $this->terms(checkCommand: 'bin/check --fast'));

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Unmoved));

        self::assertSame(Standing::Stale, $standing->standing);
        self::assertSame([IdentityField::CheckCommand], $standing->changed);
    }

    /**
     * @param non-empty-string $prompt
     */
    #[TestWith([QuorumPolicy::Majority, Tier::Strong, 'Review the change.'], 'another quorum')]
    #[TestWith([QuorumPolicy::All, Tier::Light, 'Review the change.'], 'another tier')]
    #[TestWith([QuorumPolicy::All, Tier::Strong, 'Review the change for security.'], 'another prompt')]
    public function testANewPanelPolicyMakesTheVerdictStale(QuorumPolicy $quorum, Tier $tier, string $prompt): void
    {
        $current = $this->identity(terms: $this->terms(panel: $this->panel($quorum, $tier, $prompt)));

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Unmoved));

        self::assertSame(Standing::Stale, $standing->standing);
        self::assertSame([IdentityField::PanelPolicy], $standing->changed);
    }

    public function testACleanMergeOfTheBaseKeepsTheVerdictExceptForWhatTheMergeTouched(): void
    {
        $current = $this->identity(
            head: self::NEW_HEAD,
            base: self::NEW_BASE,
        );

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::CleanMergeOfBase, 'src/Main.php'));

        self::assertSame(Standing::KeptForUntouchedParts, $standing->standing);
        self::assertSame([IdentityField::Head, IdentityField::Base], $standing->changed);
        self::assertSame(['src/Main.php'], [...$standing->reviewAgain]);
    }

    public function testADocumentationOnlyChangeKeepsTheVerdictExceptForTheTouchedPages(): void
    {
        $current = $this->identity(head: self::NEW_HEAD);

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Commits, 'docs/user/README.md', 'docs/guide/glossary.md'));

        self::assertSame(Standing::KeptForUntouchedParts, $standing->standing);
        self::assertSame(['docs/user/README.md', 'docs/guide/glossary.md'], [...$standing->reviewAgain]);
    }

    #[TestWith(['README.md'], 'documentation outside the docs folder')]
    #[TestWith(['src/docs/Feature.php'], 'a docs folder below the root')]
    public function testAChangeThatAlsoTouchesAPathOutsideTheDocsFolderMakesTheVerdictStale(string $path): void
    {
        $current = $this->identity(head: self::NEW_HEAD);

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Commits, 'docs/user/README.md', $path));

        self::assertSame(Standing::Stale, $standing->standing);
    }

    public function testADocumentationOnlyChangeThatAlsoMovedTheBaseMakesTheVerdictStale(): void
    {
        $current = $this->identity(
            head: self::NEW_HEAD,
            base: self::NEW_BASE,
        );

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Commits, 'docs/user/README.md'));

        self::assertSame(Standing::Stale, $standing->standing);
    }

    public function testARewrittenHeadMakesTheVerdictStale(): void
    {
        $current = $this->identity(head: self::NEW_HEAD);

        $standing = $this->reviewed()->standingAt($current, $this->move(HeadMoveKind::Rewritten));

        self::assertSame(Standing::Stale, $standing->standing);
    }

    #[TestWith([HeadMoveKind::CleanMergeOfBase, 'src/Main.php'], 'a clean merge of the base')]
    #[TestWith([HeadMoveKind::Commits, 'docs/user/README.md'], 'a documentation-only change')]
    public function testNeitherExceptionKeepsAVerdictWhenTheTaskAlsoChanged(HeadMoveKind $kind, string $path): void
    {
        $current = $this->identity(self::NEW_HEAD, self::NEW_BASE, $this->terms(task: 'Add another thing.'));

        $standing = $this->reviewed()->standingAt($current, $this->move($kind, $path));

        self::assertSame(Standing::Stale, $standing->standing);
        self::assertSame([IdentityField::Head, IdentityField::Base, IdentityField::TaskRevision], $standing->changed);
    }

    public function testARecordedIdentityReadsBackAsTheSameIdentity(): void
    {
        $recorded = $this->reviewed()->recorded();

        $standing = VerdictIdentity::fromRecorded($recorded)->standingAt($this->reviewed(), $this->move(HeadMoveKind::Unmoved));

        self::assertSame(Standing::Current, $standing->standing);
        self::assertStringStartsWith(self::REVIEWED_HEAD.'.'.self::BASE.'.', $recorded);
    }

    #[TestWith(['aaaa'], 'too few parts')]
    #[TestWith(['a.b.c.d.e.f'], 'too many parts')]
    public function testARecordedIdentityWithoutFivePartsIsRefusedAsMalformed(string $recorded): void
    {
        $this->expectExceptionObject(new RecordedIdentityMalformed('A recorded verdict identity has five parts separated by dots.'));

        VerdictIdentity::fromRecorded($recorded);
    }

    public function testARecordedIdentityWhoseTermsAreNoHashesIsRefusedAsAMalformedTerm(): void
    {
        $this->expectExceptionObject(new ReviewTermMalformed('A review term is a lowercase hexadecimal SHA-256 hash.'));

        VerdictIdentity::fromRecorded(self::REVIEWED_HEAD.'.'.self::BASE.'.x.y.z');
    }

    public function testARecordedIdentityWhoseHeadIsNoCommitIsRefusedAsAMalformedCommitId(): void
    {
        $this->expectException(CommitIdMalformed::class);

        VerdictIdentity::fromRecorded('HEAD.'.self::BASE.'.x.y.z');
    }

    private function reviewed(): VerdictIdentity
    {
        return $this->identity();
    }

    private function identity(string $head = self::REVIEWED_HEAD, string $base = self::BASE, ?ReviewTerms $terms = null): VerdictIdentity
    {
        return new VerdictIdentity(new CommitRange(new CommitId($base), new CommitId($head)), $terms ?? $this->terms());
    }

    private function terms(string $task = 'Add the thing.', string $checkCommand = 'bin/check', ?Panel $panel = null): ReviewTerms
    {
        return ReviewTerms::of($task, $checkCommand, $panel ?? $this->panel(QuorumPolicy::All, Tier::Strong, 'Review the change.'));
    }

    /**
     * @param non-empty-string $prompt
     */
    private function panel(QuorumPolicy $quorum, Tier $tier, string $prompt): Panel
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', $prompt), new ReviewerModel($tier, Provider::Anthropic), 10);

        return new Panel([$reviewer, new PanelReviewer(new ReviewerRole('second', 'Review it too.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10)], $quorum);
    }

    private function move(HeadMoveKind $kind, string ...$paths): HeadMove
    {
        return new HeadMove($kind, array_values($paths));
    }
}
