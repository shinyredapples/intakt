<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\ServeReviewTools\IssueReviewCredentialCommand;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(IssueReviewCredentialCommand::class)]
#[UsesClass(ReviewCredential::class)]
final class IssueReviewCredentialCommandTest extends KernelTestCase
{
    private RoundFixture $fixture;

    private CommandTester $command;

    public function testARoundUnderReviewGetsTheServerPathAndItsOwnCredential(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $credential = self::getContainer()->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);

        $exit = $this->command->execute(['round' => $round]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Review server of round '.$round.': /mcp/review', $this->command->getDisplay());
        self::assertStringContainsString('Authorization: Bearer '.$credential->issueFor($round), $this->command->getDisplay());
    }

    public function testAnUnknownRoundGetsNoCredential(): void
    {
        $exit = $this->command->execute(['round' => Uuid::v4()->toRfc4122()]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringNotContainsString('Bearer', $this->command->getDisplay());
    }

    public function testAJudgedRoundGetsNoCredential(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Approve, [], [], [])]));
        }

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))(new JudgeRound($round));

        $exit = $this->command->execute(['round' => $round]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('no longer under review', $this->command->getDisplay());
    }

    protected function setUp(): void
    {
        $this->command = new CommandTester(new Application(self::bootKernel())->find('intakt:review:issue-credential'));
        $this->fixture = new RoundFixture(self::getContainer());
    }
}
