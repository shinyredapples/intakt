<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckHandBack::class)]
#[CoversClass(BundleFile::class)]
#[CoversClass(GivenHistory::class)]
#[CoversClass(IncomingObjects::class)]
#[CoversClass(RepositoryGit::class)]
#[CoversClass(StrippedCommits::class)]
#[UsesClass(BundleSecretScan::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(HandBackNotChecked::class)]
#[UsesClass(HandBackBranchRefused::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class CheckHandBackTest extends TestCase
{
    private BuildHistory $history;

    public function testNewCommitsOnABranchNotPushedYetAreAcceptedOnTopOfMain(): void
    {
        $this->history->commit('First');
        $head = $this->history->commit('Second');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testAFirstBuildIsAcceptedWhenMainMovedInTheRepositoryWhileItRan(): void
    {
        $head = $this->history->commit('Build work');
        $bundle = $this->history->bundle('main..task');
        $this->movedMain();

        $result = $this->check($bundle);

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testABranchNotPushedYetThatSharesNoHistoryWithMainIsRefused(): void
    {
        $this->history->restartAsOrphan('task');
        $this->history->commit('Unrelated');

        $result = $this->check($this->history->bundle('task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotFastForward), $result);
    }

    public function testABranchNotPushedYetThatMergesMainIntoAHistoryOfItsOwnIsRefused(): void
    {
        $this->history->restartAsOrphan('task');
        $this->history->commit('Unrelated');
        $this->history->mergeUnrelated('main');

        $result = $this->checkWithMergeOfMain($this->history->bundle('task', '^main'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotFastForward), $result);
    }

    public function testACommitWhoseTreeIsMissingIsRefused(): void
    {
        $this->history->commitWithAMissingTree('Broken tree');

        $result = $this->check($this->history->bundleOfTheHeadCommitAlone());

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Incomplete), $result);
    }

    public function testACommitWhoseFileIsMissingIsRefused(): void
    {
        $this->history->commitWithAMissingFile('Missing file');

        $result = $this->check($this->history->bundleOfTheHeadCommitAlone());

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Incomplete), $result);
    }

    public function testACommitWhoseObjectsAreAllInTheBundleIsAcceptedWhenBundledByHand(): void
    {
        $head = $this->history->commit('Complete');

        $result = $this->check($this->history->bundleOfTheHeadCommitAlone());

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testNewCommitsOnThePushedBranchAreAcceptedOnTopOfIt(): void
    {
        $pushed = $this->history->commit('First');
        $this->history->push('task');
        $head = $this->history->commit('Second');

        $result = $this->check($this->history->bundle($pushed.'..task'));

        self::assertEquals(new AcceptedHandBack($pushed, $head, 0), $result);
    }

    public function testABundleThatUpdatesMainIsRefused(): void
    {
        $this->history->commit('First');
        $this->history->checkout('main');
        $this->history->commit('Straight onto main');

        $result = $this->check($this->history->bundle('main', 'task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::TouchesMain), $result);
    }

    public function testABundleOfAnotherBranchIsRefused(): void
    {
        $this->history->startBranch('other', 'main');
        $this->history->commit('Elsewhere');

        $result = $this->check($this->history->bundle('main..other'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NamesAnotherRef), $result);
    }

    public function testABundleThatIsNotAFastForwardOfThePushedBranchIsRefused(): void
    {
        $this->history->commit('Pushed');
        $this->history->push('task');
        $this->history->startBranch('task', 'main');
        $this->history->commit('Rewritten');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotFastForward), $result);
    }

    public function testABundleBuiltOnACommitTheRepositoryDoesNotHoldIsRefused(): void
    {
        $unknown = $this->history->commit('Never pushed');
        $this->history->commit('Second');

        $result = $this->check($this->history->bundle($unknown.'..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotOnBranch), $result);
    }

    public function testABundleBuiltOnAnotherBranchOfTheRepositoryIsRefused(): void
    {
        $this->history->startBranch('other', 'main');
        $other = $this->history->commit('Elsewhere');
        $this->history->push('other');
        $this->history->startBranch('task', $other);
        $this->history->commit('On top of the other branch');

        $result = $this->check($this->history->bundle($other.'..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotOnBranch), $result);
    }

    public function testABundleWhoseHeaderLeavesOutAPrerequisiteOnAnotherBranchIsRefused(): void
    {
        $this->history->startBranch('other', 'main');
        $other = $this->history->commit('Elsewhere');
        $this->history->push('other');
        $head = $this->history->commit('On top of the other branch');

        $result = $this->check($this->history->bundleByHand($head, [], $head, '^'.$other));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotOnBranch), $result);
    }

    public function testABundleThatNamesAnotherBranchsTipWithoutShippingItIsRefused(): void
    {
        $this->history->startBranch('other', 'main');
        $tip = $this->history->commit('Elsewhere');
        $this->history->push('other');

        $result = $this->check($this->history->bundleByHand($tip, []));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotOnBranch), $result);
    }

    public function testABundleThatShipsACommitAnotherBranchAlsoHoldsIsAccepted(): void
    {
        $this->history->startBranch('other', 'main');
        $other = $this->history->commit('Elsewhere');
        $this->history->push('other');
        $this->history->startBranch('task', $other);
        $head = $this->history->commit('On top of the other branch');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testAnObjectTheBundleOnlyUsesAsTheBaseOfADeltaIsRefused(): void
    {
        $lines = implode("\n", range(1, 2000));
        $this->history->startBranch('other', 'main');
        $other = $this->history->commitFile('file', $lines, 'Elsewhere');
        $this->history->push('other');
        $this->history->startBranch('task', 'main');
        $this->history->commitFile('second', 'second', 'Second file');
        $this->history->commitFile('file', $lines, 'Same file as elsewhere');
        $head = $this->history->commitFile('file', $lines."\nchanged", 'Changed file');
        $main = $this->history->inMirror('rev-parse', 'refs/heads/main');

        $result = $this->check($this->history->bundleByHand($head, [$main], $head, '^'.$main, '^'.$other));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Incomplete), $result);
    }

    public function testARefusedBundleLeavesNoObjectInTheRepository(): void
    {
        $head = $this->history->commitWithoutAnAuthor('Bad');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Malformed), $result);
        self::assertFalse($this->history->mirrorHolds($head));
        self::assertSame([], $this->history->quarantinesLeftInTheMirror());
    }

    public function testAnAcceptedBundleLeavesItsCommitsAndTheStrippedOnesInTheRepository(): void
    {
        $first = $this->history->commit('First');
        $this->history->commit("Second\n\nGenerated with a tool");

        $result = $this->check($this->history->bundle('main..task'));

        self::assertInstanceOf(AcceptedHandBack::class, $result);
        self::assertTrue($this->history->mirrorHolds($first));
        self::assertTrue($this->history->mirrorHolds($result->head));
        self::assertSame([], $this->history->quarantinesLeftInTheMirror());
    }

    public function testAFileThatIsNoBundleIsRefused(): void
    {
        $result = $this->check($this->history->file("not a bundle\n\n"));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Unreadable), $result);
    }

    public function testAMissingBundleIsRefused(): void
    {
        $result = $this->check($this->history->folder.'/missing.bundle');

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Unreadable), $result);
    }

    public function testAFolderInPlaceOfTheBundleIsRefused(): void
    {
        $result = $this->check($this->history->folder);

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Unreadable), $result);
    }

    public function testAHeaderWithoutItsEndIsRefused(): void
    {
        $result = $this->check($this->history->file("# v2 git bundle\n".str_repeat('a', 40).' refs/heads/task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Unreadable), $result);
    }

    public function testAHeaderAtTheReadLimitIsRead(): void
    {
        $result = $this->check($this->history->file($this->headerOfLength(BundleFile::HEADER_LIMIT)));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::NotOnBranch), $result);
    }

    public function testAHeaderOneBytePastTheReadLimitIsRefused(): void
    {
        $result = $this->check($this->history->file($this->headerOfLength(BundleFile::HEADER_LIMIT + 1)));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Unreadable), $result);
    }

    public function testABundleWithABrokenPackIsRefused(): void
    {
        $this->history->commit('First');
        $bundle = $this->history->bundle('main..task');
        $content = (string) file_get_contents($bundle);
        file_put_contents($bundle, substr($content, 0, (int) strpos($content, "\n\n") + 2).'PACK broken');

        $result = $this->check($bundle);

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Unreadable), $result);
    }

    public function testAMergeCommitIsRefusedWhenNoMergeOfMainWasAsked(): void
    {
        $this->history->commit('First');
        $this->movedMain();
        $this->history->checkout('task');
        $this->history->merge('main');

        $result = $this->check($this->history->bundle('task', '^main'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsMerge), $result);
    }

    public function testAMergeOfMainIsAcceptedWhenAskedAndMainsCommitsKeepTheirAttribution(): void
    {
        $base = $this->history->commit('First');
        $this->history->push('task');
        $main = $this->movedMain();
        $this->history->checkout('task');
        $merge = $this->history->merge('main');

        $result = $this->checkWithMergeOfMain($this->history->bundle('task', '^main', '^'.$base));

        self::assertEquals(new AcceptedHandBack($base, $merge, 0), $result);
        self::assertStringContainsString('Co-authored-by', $this->history->inMirror('log', '-1', '--format=%B', $main));
    }

    public function testAMergeOfAnotherBranchIsRefusedEvenWhenAMergeOfMainWasAsked(): void
    {
        $this->history->startBranch('side', 'main');
        $this->history->commit('Side work');
        $this->history->checkout('task');
        $this->history->commit('First');
        $this->history->merge('side');

        $result = $this->checkWithMergeOfMain($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::MergesAnotherBranch), $result);
    }

    public function testAttributionIsStrippedFromEveryNewCommitAndTheCommitsAfterItAreRebuiltOnTheStrippedOnes(): void
    {
        $main = $this->history->inMirror('rev-parse', 'refs/heads/main');
        $this->history->commit("First\n\nCo-authored-by: Bot <bot@example.invalid>");
        $this->history->commit('Second');
        $head = $this->history->commit("Third\n\nGenerated with a tool");

        $result = $this->check($this->history->bundle('main..task'));

        self::assertInstanceOf(AcceptedHandBack::class, $result);
        self::assertNotSame($head, $result->head);
        self::assertSame(3, $result->rewrittenCommits);
        self::assertSame("Third\n\nSecond\n\nFirst\n", $this->history->inMirror('log', '--format=%B', $main.'..'.$result->head)."\n");
    }

    public function testAMergeOfMainWithAttributionIsRebuiltOnItsStrippedFirstParentAndKeepsMainAsItsSecond(): void
    {
        $base = $this->history->commit('First');
        $this->history->push('task');
        $main = $this->movedMain();
        $this->history->checkout('task');
        $this->history->commit("Second\n\nCo-authored-by: Bot <bot@example.invalid>");
        $this->history->merge('main');

        $result = $this->checkWithMergeOfMain($this->history->bundle('task', '^main', '^'.$base));

        self::assertInstanceOf(AcceptedHandBack::class, $result);
        self::assertSame(2, $result->rewrittenCommits);
        $parents = explode(' ', $this->history->inMirror('log', '-1', '--format=%P', $result->head));
        self::assertSame("Second\n", $this->history->inMirror('log', '-1', '--format=%B', $parents[0])."\n");
        self::assertSame($main, $parents[1]);
    }

    public function testAStrippedCommitLosesItsSignatureForTheOrchestratorToSignIt(): void
    {
        $this->history->commit('First');
        $this->history->commitWithASignatureHeader("Signed\n\nCo-authored-by: Bot <bot@example.invalid>");

        $result = $this->check($this->history->bundle('main..task'));

        self::assertInstanceOf(AcceptedHandBack::class, $result);
        $commit = $this->history->inMirror('cat-file', 'commit', $result->head);
        self::assertStringNotContainsString('gpgsig', $commit);
        self::assertStringNotContainsString('notarealsignature', $commit);
        self::assertStringEndsWith("\n\nSigned", $commit);
    }

    public function testASignedCommitWithoutAttributionKeepsItsIdAndSignature(): void
    {
        $this->history->commit('First');
        $head = $this->history->commitWithASignatureHeader('Signed');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testAMalformedCommitWithAttributionIsRefused(): void
    {
        $this->history->commitWithoutAnAuthor("Bad\n\nCo-authored-by: Bot <bot@example.invalid>");

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Malformed), $result);
    }

    public function testAMalformedCommitWithoutAttributionIsRefused(): void
    {
        $this->history->commitWithoutAnAuthor('Bad');
        $this->history->commit('After it');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Malformed), $result);
    }

    public function testARepositoryGivenByARelativePathIsChecked(): void
    {
        $head = $this->history->commit('First');
        $bundle = $this->history->bundle('main..task');
        $directory = (string) getcwd();
        chdir($this->history->folder);

        try {
            $result = $this->checker()->check(new HandBack('mirror.git', 'task', $bundle, false, new JobCredentials()));
        } finally {
            chdir($directory);
        }

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testARepositoryWhosePathHoldsAColonAndAQuoteIsChecked(): void
    {
        $head = $this->history->commit('First');
        $repository = $this->history->folder.'/with:colon"quote.git';
        rename($this->history->mirror, $repository);

        $result = $this->checker()->check(new HandBack($repository, 'task', $this->history->bundle('main..task'), false, new JobCredentials()));

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testAMissingRepositoryIsNotChecked(): void
    {
        $this->expectException(HandBackNotChecked::class);

        (void) $this->checker()->check(new HandBack($this->history->folder.'/missing.git', 'task', $this->history->file(''), false, new JobCredentials()));
    }

    public function testTheRefusalNamesNoValueFromTheBundle(): void
    {
        $this->history->startBranch('leaked-value-branch', 'main');
        $commit = $this->history->commit('Elsewhere');

        $result = $this->check($this->history->bundle('main..leaked-value-branch'));

        self::assertInstanceOf(RefusedHandBack::class, $result);
        self::assertStringNotContainsString('leaked-value-branch', $result->reason());
        self::assertStringNotContainsString(substr($commit, 0, 7), $result->reason());
    }

    public function testABranchThatIsMainItselfIsRefusedWithItsName(): void
    {
        $this->expectExceptionObject(HandBackBranchRefused::isMain('main'));

        (void) $this->checker()->check(new HandBack($this->history->mirror, 'main', $this->history->file(''), false, new JobCredentials()));
    }

    public function testABranchNameGitRefusesIsRefusedWithItsName(): void
    {
        $this->expectExceptionObject(HandBackBranchRefused::notAccepted('a..b'));

        (void) $this->checker()->check(new HandBack($this->history->mirror, 'a..b', $this->history->file(''), false, new JobCredentials()));
    }

    public function testAFolderThatIsNoRepositoryIsNotChecked(): void
    {
        $this->expectException(HandBackNotChecked::class);

        (void) $this->checker()->check(new HandBack($this->history->folder, 'task', $this->history->file(''), false, new JobCredentials()));
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    private function movedMain(): string
    {
        $this->history->checkout('main');
        $main = $this->history->commit("On main\n\nCo-authored-by: Someone <someone@example.invalid>");
        $this->history->push('main');

        return $main;
    }

    // A prerequisite the repository does not hold, so a header the limit lets through is refused one step later, before the pack is read.
    private function headerOfLength(int $length): string
    {
        $signature = "# v2 git bundle\n";
        $reference = str_repeat('b', 40)." refs/heads/task\n";
        $prerequisite = '-'.str_repeat('a', 40).' ';
        $padding = str_repeat('x', $length - strlen($signature.$prerequisite."\n".$reference));

        return $signature.$prerequisite.$padding."\n".$reference."\nPACK";
    }

    private function check(string $bundle): AcceptedHandBack|RefusedHandBack
    {
        return $this->checker()->check(new HandBack($this->history->mirror, 'task', $bundle, false, new JobCredentials()));
    }

    private function checkWithMergeOfMain(string $bundle): AcceptedHandBack|RefusedHandBack
    {
        return $this->checker()->check(new HandBack($this->history->mirror, 'task', $bundle, true, new JobCredentials()));
    }

    private function checker(): CheckHandBack
    {
        return new CheckHandBack(new RepositoryGit(new HardenedGit()), new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan('gitleaks'));
    }
}
