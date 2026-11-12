<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBackCommand;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBackInput;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckHandBackCommand::class)]
#[UsesClass(CheckHandBackInput::class)]
#[UsesClass(CheckHandBack::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(GivenHistory::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(StrippedCommits::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(HandBackNotChecked::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class CheckHandBackCommandTest extends TestCase
{
    private BuildHistory $history;

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    public function testAnAcceptedBundlePrintsItsHeadThePushedBaseAndHowManyCommitsWereRewritten(): void
    {
        $base = $this->history->commit('First');
        $this->history->push('task');
        $head = $this->history->commit('Second');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle($base.'..task')]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("accepted {$head}\nbase {$base}\nrewritten 0\n", $tester->getDisplay());
    }

    public function testAnAcceptedBundleOfABranchNotPushedYetPrintsNoBase(): void
    {
        $head = $this->history->commit('First');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main..task')]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("accepted {$head}\nbase none\nrewritten 0\n", $tester->getDisplay());
    }

    public function testARefusedBundlePrintsTheReasonAndFails(): void
    {
        $this->history->commit('First');

        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $this->history->bundle('main', 'task')]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('refused: '.HandBackRefusal::TouchesMain->reason(), $this->flat($tester));
    }

    public function testAMergeOfMainPassesOnlyWithTheOption(): void
    {
        $this->history->commit('First');
        $this->history->checkout('main');
        $this->history->commit('On main');
        $this->history->push('main');
        $this->history->checkout('task');
        $this->history->merge('main');
        $bundle = $this->history->bundle('task', '^main');

        $without = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $bundle]);
        $with = $this->executed(['repository' => $this->history->mirror, 'branch' => 'task', 'bundle' => $bundle, '--merge-main' => true]);

        self::assertSame(Command::FAILURE, $without->getStatusCode());
        self::assertSame(Command::SUCCESS, $with->getStatusCode());
    }

    public function testABranchThatIsMainFailsWithTheReason(): void
    {
        $tester = $this->executed(['repository' => $this->history->mirror, 'branch' => 'main', 'bundle' => $this->history->file('')]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is main itself', $this->flat($tester));
    }

    public function testAFolderThatIsNoRepositoryFailsWithTheReason(): void
    {
        $tester = $this->executed(['repository' => $this->history->folder, 'branch' => 'task', 'bundle' => $this->history->file('')]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('could not be checked', $this->flat($tester));
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function executed(array $input): CommandTester
    {
        $git = new RepositoryGit(new HardenedGit());
        $application = new Application();
        $application->addCommand(new CheckHandBackCommand(new CheckHandBack($git, new BundleFile(), new StrippedCommits($git))));
        $tester = new CommandTester($application->find('intakt:hand-back:check'));
        $tester->execute($input);

        return $tester;
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
