<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Infrastructure\AccountPicked;
use Intakt\Core\Budget\Infrastructure\LedgerAccountPicks;
use Intakt\Core\Budget\Infrastructure\PickedLeftOut;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use Intakt\Tests\Support\StoredSerializerFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerAccountPicks::class)]
#[CoversClass(AccountPicked::class)]
#[CoversClass(PickedLeftOut::class)]
#[CoversClass(AccountPick::class)]
#[UsesClass(RankedAccounts::class)]
#[UsesClass(LeftOutAccount::class)]
#[UsesClass(Account::class)]
#[UsesClass(LedgerPayloads::class)]
final class LedgerAccountPicksTest extends TestCase
{
    use AssertsLoggedErrors;

    private InMemoryLedger $ledger;

    public function testAPickIsWrittenAsOneLedgerRowAboutItsProvider(): void
    {
        $this->picks()->record(Provider::ClaudeCode, $this->teamPickedWhileDefaultIsAtItsWeeklyCeiling());

        self::assertSame([LedgerEventKind::AccountPicked], $this->ledger->kinds());
        $row = array_first(iterator_to_array($this->ledger->readOfKindAbout(LedgerEventKind::AccountPicked, 'claude_code')));
        self::assertNotNull($row);
        self::assertSame('{"provider":"claude_code","picked":"team","left_out":[{"account":"default","cause":"weekly-ceiling","resets_at":"2026-11-30T12:00:00+00:00"}]}', $row->event->payload);
    }

    public function testARecordedPickReadsBackWithItsAccountAndEachLeftOutCauseAndReset(): void
    {
        $picks = $this->picks();
        $picks->record(Provider::ClaudeCode, $this->teamPickedWhileDefaultIsAtItsWeeklyCeiling());

        $pick = $picks->latestOf(Provider::ClaudeCode);

        self::assertNotNull($pick);
        self::assertEquals(new Account(Provider::ClaudeCode, 'team'), $pick->picked);
        self::assertEquals([new LeftOutAccount(new Account(Provider::ClaudeCode, Account::DEFAULT), LeftOutCause::AtWeeklyCeiling, new DateTimeImmutable('2026-11-30T12:00:00+00:00'))], $pick->leftOut);
    }

    public function testAStartWithEveryAccountLeftOutReadsBackWithNoAccountAndTheEarliestReset(): void
    {
        $picks = $this->picks();
        $picks->record(Provider::ClaudeCode, new RankedAccounts([], [
            $this->leftOut(Account::DEFAULT, LeftOutCause::AtWeeklyCeiling, new DateTimeImmutable('2026-11-30T12:00:00+00:00')),
            $this->leftOut('team', LeftOutCause::AtShortWindowCeiling, new DateTimeImmutable('2026-11-28T15:00:00+00:00')),
            new LeftOutAccount(new Account(Provider::ClaudeCode, 'spare'), LeftOutCause::CoolingDown, null),
        ]));

        $pick = $picks->latestOf(Provider::ClaudeCode);

        self::assertNotNull($pick);
        self::assertNull($pick->picked);
        self::assertEquals(new DateTimeImmutable('2026-11-28T15:00:00+00:00'), $pick->earliestReset());
    }

    public function testEveryLeftOutAccountWithoutAKnownEndGivesNoEarliestReset(): void
    {
        $pick = new AccountPick(Provider::ClaudeCode, null, [new LeftOutAccount(new Account(Provider::ClaudeCode, 'team'), LeftOutCause::AtWeeklyCeiling, null)], new DateTimeImmutable('2026-11-28T12:00:00+00:00'));

        self::assertNull($pick->earliestReset());
    }

    public function testTheNewestPickOfTheProviderIsTheOneRead(): void
    {
        $picks = $this->picks();
        $picks->record(Provider::ClaudeCode, $this->teamPickedWhileDefaultIsAtItsWeeklyCeiling());
        $picks->record(Provider::ClaudeCode, new RankedAccounts([new Account(Provider::ClaudeCode, Account::DEFAULT)], []));

        self::assertEquals(new Account(Provider::ClaudeCode, Account::DEFAULT), $picks->latestOf(Provider::ClaudeCode)?->picked);
    }

    public function testTheNewestPickIsReadWithoutLoadingTheOlderPicks(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKindAbout')->willThrowException(new LogicException('Every pick of the provider was read.'));
        $ledger->method('newestOfKindAbout')->willReturn(new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::AccountPicked, '{"provider":"claude_code","picked":"team","left_out":[]}', 'claude_code'), new DateTimeImmutable('2026-11-28T12:00:00+00:00')));

        $picks = new LedgerAccountPicks($ledger, new LedgerPayloads(StoredSerializerFactory::create()), StoredSerializerFactory::create(), new NullLogger());

        self::assertEquals(new Account(Provider::ClaudeCode, 'team'), $picks->latestOf(Provider::ClaudeCode)?->picked);
    }

    public function testAProviderWithNoRecordedStartHasNoPick(): void
    {
        $picks = $this->picks();
        $picks->record(Provider::ClaudeCode, $this->teamPickedWhileDefaultIsAtItsWeeklyCeiling());

        self::assertNull($picks->latestOf(Provider::Codex));
    }

    public function testANewestRowNamingAnAccountNoAccountCanHaveReadsAsNoPick(): void
    {
        $picks = $this->picks();
        $picks->record(Provider::ClaudeCode, $this->teamPickedWhileDefaultIsAtItsWeeklyCeiling());
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::AccountPicked, '{"provider":"claude_code","picked":"Not An Account","left_out":[]}', 'claude_code'))->succeeded());

        self::assertNull($picks->latestOf(Provider::ClaudeCode));
    }

    public function testANewestRowOfAnotherProviderUnderTheSubjectReadsAsNoPick(): void
    {
        $picks = $this->picks();
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::AccountPicked, '{"provider":"codex","picked":"team","left_out":[]}', 'claude_code'))->succeeded());

        self::assertNull($picks->latestOf(Provider::ClaudeCode));
    }

    public function testARowWithAnUnknownCauseReadsAsNoPick(): void
    {
        $picks = $this->picks();
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::AccountPicked, '{"provider":"claude_code","picked":null,"left_out":[{"account":"team","cause":"tired","resets_at":null}]}', 'claude_code'))->succeeded());

        self::assertNull($picks->latestOf(Provider::ClaudeCode));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedRows(): iterable
    {
        yield 'a null left-out entry' => ['{"provider":"claude_code","picked":null,"left_out":[null]}'];
        yield 'left-out accounts keyed by name' => ['{"provider":"claude_code","picked":null,"left_out":{"spare":{"account":"spare","cause":"weekly-ceiling","resets_at":null}}}'];
        yield 'a relative reset' => ['{"provider":"claude_code","picked":null,"left_out":[{"account":"spare","cause":"weekly-ceiling","resets_at":"tomorrow"}]}'];
        yield 'a reset without its offset' => ['{"provider":"claude_code","picked":null,"left_out":[{"account":"spare","cause":"weekly-ceiling","resets_at":"2026-11-30 12:00:00"}]}'];
        yield 'no left-out list' => ['{"provider":"claude_code","picked":"team"}'];
    }

    #[DataProvider('malformedRows')]
    public function testAMalformedNewestRowReadsAsNoPick(string $payload): void
    {
        $picks = $this->picks();
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::AccountPicked, $payload, 'claude_code'))->succeeded());

        self::assertNull($picks->latestOf(Provider::ClaudeCode));
    }

    public function testARefusedAppendIsLoggedAndDoesNotThrow(): void
    {
        $this->ledger = new InMemoryLedger(0);
        $picks = new LedgerAccountPicks($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()), StoredSerializerFactory::create(), new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No test log records.')));

        $picks->record(Provider::ClaudeCode, $this->teamPickedWhileDefaultIsAtItsWeeklyCeiling());

        self::assertSame(0, $this->ledger->count());
        self::assertLoggedError('error', 'An account pick was not recorded.');
    }

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
    }

    private function picks(): LedgerAccountPicks
    {
        return new LedgerAccountPicks($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()), StoredSerializerFactory::create(), new NullLogger());
    }

    private function teamPickedWhileDefaultIsAtItsWeeklyCeiling(): RankedAccounts
    {
        return new RankedAccounts([new Account(Provider::ClaudeCode, 'team')], [$this->leftOut(Account::DEFAULT, LeftOutCause::AtWeeklyCeiling, new DateTimeImmutable('2026-11-30T12:00:00+00:00'))]);
    }

    private function leftOut(string $name, LeftOutCause $cause, DateTimeImmutable $resetsAt): LeftOutAccount
    {
        return new LeftOutAccount(new Account(Provider::ClaudeCode, $name), $cause, $resetsAt);
    }
}
