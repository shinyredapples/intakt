<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\GuardMain;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Domain\HaltClearedPayload;
use Intakt\Core\Watchdog\Domain\HaltPayload;
use Intakt\Core\Watchdog\Infrastructure\ConsoleReport;
use Intakt\Core\Watchdog\Infrastructure\FirstParentChain;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\Infrastructure\OwnerKey;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\GuardMain\AHaltNamesItsProject;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMain;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMainCommand;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMainInput;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Watchdog\HaltLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

#[CoversClass(GuardMainCommand::class)]
#[CoversClass(GuardMain::class)]
#[CoversClass(GuardMainInput::class)]
#[CoversClass(AHaltNamesItsProject::class)]
#[CoversClass(ConsoleReport::class)]
#[UsesClass(CheckMain::class)]
#[UsesClass(CommitClassifier::class)]
#[UsesClass(MainHistory::class)]
#[UsesClass(FirstParentChain::class)]
#[UsesClass(OwnerKey::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(Classification::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(MainNotRead::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(WatchedMain::class)]
#[UsesClass(LedgerHalts::class)]
#[UsesClass(OwnerCard::class)]
#[UsesClass(HaltNotRecorded::class)]
#[UsesClass(HaltPayload::class)]
#[UsesClass(HaltClearedPayload::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(AppendResult::class)]
final class GuardMainCommandTest extends TestCase
{
    private const string PROJECT = 'acme/shop';

    private TemporaryRepository $repository;
    private string $keys;
    private HaltLedger $ledger;
    private LedgerHalts $halts;

    #[Override]
    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->keys = sys_get_temp_dir().'/intakt-watchdog-keys-'.bin2hex(random_bytes(6));
        mkdir($this->keys);
        $this->ledger = new HaltLedger();
        $this->halts = new LedgerHalts($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->repository->remove();
        new Filesystem()->remove($this->keys);
    }

    public function testASeededUnsignedCommitHaltsTheProjectWithACardNamingTheCommitAndExitsWithOne(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Seeded unsigned commit');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $card = $this->onlyStandingCard();
        self::assertSame(sprintf("card %s halts acme/shop: commit %s unsigned, raised 2026-11-21T09:00:00+00:00\n", $card->id->toRfc4122(), $unsigned), $tester->getDisplay());
        self::assertSame([LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testACommitSignedByAnUnknownKeyHaltsWithAnUnknownKeyCard(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $foreign = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Foreign change');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$foreign.' unknown_key', $tester->getDisplay());
        self::assertCount(1, $this->halts->standingOf(self::PROJECT));
    }

    public function testAForcePushHaltsWithAHistoryRewrittenCardOnTheLastSeenHead(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen, then dropped');
        $this->repository->resetTo($base);
        $this->repository->commitEmpty('Rewritten main');

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$seen.' history_rewritten', $tester->getDisplay());
        self::assertCount(1, $this->halts->standingOf(self::PROJECT));
    }

    public function testAnOwnerSignedCommitRaisesNoCardAndExitsWithZero(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        $tester = $this->whenTheGuardRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("acme/shop is not halted: main is clean\n", $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAStandingCardKeepsTheProjectHaltedWhileMainIsCleanAgain(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Seeded unsigned commit');
        $this->whenTheGuardRuns($seen, []);

        $tester = $this->whenTheGuardRuns($unsigned, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('commit '.$unsigned.' unsigned', $tester->getDisplay());
    }

    public function testAViolationSeenAgainRaisesNoSecondCard(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');

        $this->whenTheGuardRuns($seen, []);
        $this->whenTheGuardRuns($seen, []);

        self::assertSame([LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testAViolationTheOwnerClearedRaisesNoNewCard(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');
        $this->whenTheGuardRuns($seen, []);
        $this->halts->clear($this->onlyStandingCard());

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::HaltCleared], $this->ledger->kinds());
    }

    public function testAMainThatCannotBeReadRaisesNoCardAndIsNeverReportedClean(): void
    {
        $tester = $this->whenTheGuardRunsIn($this->repository->path.'/missing', self::PROJECT, str_repeat('a', 40));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Main was not read', $tester->getDisplay());
        self::assertStringNotContainsString('is not halted', $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAHaltTheLedgerRefusesExitsWithOneAndIsNeverReportedClean(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');
        $this->ledger->refuseAppends();

        $tester = $this->whenTheGuardRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('was not recorded', $tester->getDisplay());
        self::assertStringNotContainsString('is not halted', $tester->getDisplay());
    }

    public function testAnEmptyProjectIsRefusedWithTwoBeforeMainIsRead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('Seeded unsigned commit');

        $tester = $this->whenTheGuardRunsIn($this->repository->path, ' ', $seen);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('A halt names its project', $tester->getDisplay());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAProjectNameWithALineBreakIsPrintedOnOneEscapedLine(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheGuardRunsIn($this->repository->path, "acme/shop\nforged", $seen);

        self::assertSame("acme/shop\\nforged is not halted: main is clean\n", $tester->getDisplay());
    }

    private function onlyStandingCard(): OwnerCard
    {
        $standing = $this->halts->standing();
        self::assertCount(1, $standing);

        return $standing[0];
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function whenTheGuardRuns(string $seen, array $options): CommandTester
    {
        return $this->whenTheGuardRunsIn($this->repository->path, self::PROJECT, $seen, $options);
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function whenTheGuardRunsIn(string $path, string $project, string $seen, array $options = []): CommandTester
    {
        $guard = new GuardMain(new CheckMain(new HardenedGit(), new Filesystem()), $this->halts, new LockFactory(new InMemoryStore()));
        $application = new Application();
        $application->addCommand(new GuardMainCommand($guard));
        $tester = new CommandTester($application->find(GuardMainCommand::NAME));
        $tester->execute(['project' => $project, 'repository' => $path, 'last-seen-head' => $seen, ...$options]);

        return $tester;
    }

    private function privateKey(string $name): string
    {
        $file = $this->keys.'/'.$name;
        if (!is_file($file)) {
            new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $name, '-f', $file])->mustRun();
        }

        return $file;
    }

    private function publicKey(string $name): string
    {
        $parts = explode(' ', trim((string) file_get_contents($this->privateKey($name).'.pub')));

        return $parts[0].' '.$parts[1];
    }
}
