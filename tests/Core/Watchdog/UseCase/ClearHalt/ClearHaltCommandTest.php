<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\ClearHalt;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Domain\HaltClearedPayload;
use Intakt\Core\Watchdog\Domain\HaltPayload;
use Intakt\Core\Watchdog\Infrastructure\ConsoleReport;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use Intakt\Core\Watchdog\UseCase\ClearHalt\ClearHalt;
use Intakt\Core\Watchdog\UseCase\ClearHalt\ClearHaltCommand;
use Intakt\Core\Watchdog\UseCase\ClearHalt\OnlyAStandingCardIsCleared;
use Intakt\Core\Watchdog\UseCase\ClearHalt\OnlyTheOwnerClearsAHalt;
use Intakt\Tests\Core\Watchdog\HaltLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ClearHaltCommand::class)]
#[CoversClass(ClearHalt::class)]
#[CoversClass(OnlyTheOwnerClearsAHalt::class)]
#[CoversClass(OnlyAStandingCardIsCleared::class)]
#[UsesClass(ConsoleReport::class)]
#[UsesClass(LedgerHalts::class)]
#[UsesClass(OwnerCard::class)]
#[UsesClass(OwnerToken::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(HaltNotRecorded::class)]
#[UsesClass(HaltPayload::class)]
#[UsesClass(HaltClearedPayload::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(AppendResult::class)]
final class ClearHaltCommandTest extends TestCase
{
    private const string OWNER_TOKEN = 'owner-token-of-this-test';

    private HaltLedger $ledger;
    private LedgerHalts $halts;

    protected function setUp(): void
    {
        $this->ledger = new HaltLedger();
        $this->halts = new LedgerHalts($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()));
    }

    public function testTheOwnerClearsACardAndTheProjectIsNoLongerHalted(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');

        $tester = $this->whenTheClearRuns($card, [self::OWNER_TOKEN]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('card '.$card.' cleared', $tester->getDisplay());
        self::assertStringContainsString('acme/shop is not halted', $tester->getDisplay());
        self::assertSame([], $this->halts->standing());
    }

    public function testAClearWithAnotherTokenIsRefusedAndTheHaltStands(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');

        $tester = $this->whenTheClearRuns($card, ['a-guess']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Only the owner clears a halt', $tester->getDisplay());
        self::assertCount(1, $this->halts->standing());
        self::assertSame([LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testWithoutAnOwnerTokenSetUpNoAnswerClearsAHalt(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');

        $tester = $this->whenTheClearRunsWith(new OwnerToken('', null), $card, ['']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $this->halts->standing());
    }

    public function testARunThatCannotBeAskedForTheTokenIsRefused(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');
        $tester = new CommandTester($this->application(new OwnerToken(self::OWNER_TOKEN, null))->find(ClearHaltCommand::NAME));

        $tester->execute(['card' => $card], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $this->halts->standing());
    }

    public function testTheTokenIsNeverEchoed(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');

        $tester = $this->whenTheClearRuns($card, [self::OWNER_TOKEN]);

        self::assertStringNotContainsString(self::OWNER_TOKEN, $tester->getDisplay());
    }

    public function testClearingOneCardLeavesTheOtherStandingCardOfTheProjectHaltingIt(): void
    {
        $first = $this->haltedOn('acme/shop', 'a');
        $second = $this->haltedOn('acme/shop', 'b');

        $tester = $this->whenTheClearRuns($first, [self::OWNER_TOKEN]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('card '.$second.' halts acme/shop', $tester->getDisplay());
        self::assertCount(1, $this->halts->standingOf('acme/shop'));
    }

    public function testACardThatIsNotStandingIsRefused(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');
        $this->whenTheClearRuns($card, [self::OWNER_TOKEN]);

        $tester = $this->whenTheClearRuns($card, [self::OWNER_TOKEN]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Only a standing card is cleared', $tester->getDisplay());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::HaltCleared], $this->ledger->kinds());
    }

    public function testAnIdThatNamesNoCardIsRefused(): void
    {
        $this->haltedOn('acme/shop', 'a');

        $tester = $this->whenTheClearRuns('not-a-card', [self::OWNER_TOKEN]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $this->halts->standing());
    }

    public function testACardIdInCapitalsNamesTheSameCard(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');

        $tester = $this->whenTheClearRuns(strtoupper($card), [self::OWNER_TOKEN]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAClearTheLedgerRefusesExitsWithOneAndTheHaltStands(): void
    {
        $card = $this->haltedOn('acme/shop', 'a');
        $this->ledger->refuseAppends();

        $tester = $this->whenTheClearRuns($card, [self::OWNER_TOKEN]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the ledger is down', $tester->getDisplay());
        self::assertCount(1, $this->halts->standing());
    }

    private function haltedOn(string $project, string $digit): string
    {
        $this->halts->raise($project, new MainViolation(str_repeat($digit, 40), Violation::Unsigned));

        return array_last($this->halts->standing())?->id->toRfc4122() ?? '';
    }

    /**
     * @param list<string> $answers
     */
    private function whenTheClearRuns(string $card, array $answers): CommandTester
    {
        return $this->whenTheClearRunsWith(new OwnerToken(self::OWNER_TOKEN, null), $card, $answers);
    }

    /**
     * @param list<string> $answers
     */
    private function whenTheClearRunsWith(OwnerToken $owner, string $card, array $answers): CommandTester
    {
        $tester = new CommandTester($this->application($owner)->find(ClearHaltCommand::NAME));
        $tester->setInputs($answers);
        $tester->execute(['card' => $card]);

        return $tester;
    }

    private function application(OwnerToken $owner): Application
    {
        $application = new Application();
        $application->addCommand(new ClearHaltCommand(new ClearHalt($this->halts, $owner), $this->halts));

        return $application;
    }
}
