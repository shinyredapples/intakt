<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Infrastructure\FirstParentChain;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\Infrastructure\OwnerKey;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainCommand;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainInput;
use Intakt\Core\Watchdog\UseCase\CheckMain\RecordedMergeNotARange;
use Intakt\Core\Watchdog\UseCase\CheckMain\RecordedMergeOption;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(CheckMainCommand::class)]
#[CoversClass(CheckMain::class)]
#[CoversClass(CheckMainInput::class)]
#[CoversClass(RecordedMergeOption::class)]
#[CoversClass(RecordedMergeNotARange::class)]
#[UsesClass(CommitClassifier::class)]
#[UsesClass(MainHistory::class)]
#[UsesClass(FirstParentChain::class)]
#[UsesClass(OwnerKey::class)]
#[UsesClass(OwnerKeyRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(Classification::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(CommitNameNotFull::class)]
#[UsesClass(MainNotRead::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(WatchedMain::class)]
final class CheckMainCommandTest extends TestCase
{
    private TemporaryRepository $repository;
    private string $keys;

    #[Override]
    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->keys = sys_get_temp_dir().'/intakt-watchdog-keys-'.bin2hex(random_bytes(6));
        mkdir($this->keys);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->repository->remove();
        new Filesystem()->remove($this->keys);
    }

    public function testARecordedMergeOnMainIsReportedCleanAndExitsWithZero(): void
    {
        [$seen, $merge] = $this->seenCommitAndMergeOfATaskBranch();

        $tester = $this->whenTheCommandRuns($seen, ['--recorded-merge' => [$seen.'..'.$merge]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("main is clean\n", $tester->getDisplay());
    }

    public function testASeededUnsignedCommitIsReportedAsAnUnsignedViolationAndExitsWithOne(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Seeded unsigned commit');

        $tester = $this->whenTheCommandRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('violation '.$unsigned." unsigned\n", $tester->getDisplay());
    }

    public function testACommitSignedByAnOwnerKeyGivenWithTheOptionPasses(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        $tester = $this->whenTheCommandRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("main is clean\n", $tester->getDisplay());
    }

    public function testACommitSignedByAKeyNotGivenIsReportedAsAnUnknownKeyViolation(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $foreign = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Foreign change');

        $tester = $this->whenTheCommandRuns($seen, ['--owner-key' => [$this->publicKey('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('violation '.$foreign." unknown_key\n", $tester->getDisplay());
    }

    public function testWithoutAnOwnerKeyTheCheckStillRunsAndASignedCommitIsAnUnknownKeyViolation(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        $tester = $this->whenTheCommandRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('violation '.$signed." unknown_key\n", $tester->getDisplay());
    }

    public function testEveryRecordedMergeAndEveryOwnerKeyGivenIsRead(): void
    {
        [$seen, $first] = $this->seenCommitAndMergeOfATaskBranch();
        $this->repository->commitSignedWith($this->privateKey('second-owner'), 'Second owner change');
        $base = $this->repository->commitSignedWith($this->privateKey('first-owner'), 'First owner change');
        $this->repository->checkoutNewBranch('other');
        $this->repository->commitEmpty('Other task commit');
        $this->repository->checkout('main');
        $second = $this->repository->merge('other');

        $tester = $this->whenTheCommandRuns($seen, [
            '--recorded-merge' => [$seen.'..'.$first, $base.'..'.$second],
            '--owner-key' => [$this->publicKey('first-owner'), $this->publicKey('second-owner')],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testViolationsAreListedOldestFirst(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $older = $this->repository->commitEmpty('Older unsigned commit');
        $newer = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Newer foreign commit');

        $tester = $this->whenTheCommandRuns($seen, []);

        self::assertSame('violation '.$older." unsigned\nviolation ".$newer." unknown_key\n", $tester->getDisplay());
    }

    public function testAForcePushIsReportedAsHistoryRewrittenOnTheLastSeenHead(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen, then dropped');
        $this->repository->resetTo($base);
        $this->repository->commitEmpty('Rewritten main');

        $tester = $this->whenTheCommandRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('violation '.$seen." history_rewritten\n", $tester->getDisplay());
    }

    public function testRecordedMergesOfFullSha256NamesAreRead(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        [$seen, $merge] = $this->seenCommitAndMergeOfATaskBranch();

        $tester = $this->whenTheCommandRuns($seen, ['--recorded-merge' => [$seen.'..'.$merge]]);

        self::assertSame(64, strlen($merge));
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testASha256RecordedMergeBaseCutToFortyCharactersExitsWithTwo(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        [$seen, $merge] = $this->seenCommitAndMergeOfATaskBranch();

        $tester = $this->whenTheCommandRuns($seen, ['--recorded-merge' => [substr($seen, 0, 40).'..'.$merge]]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('recorded merge base', $tester->getDisplay());
        self::assertStringNotContainsString('main is clean', $tester->getDisplay());
    }

    public function testASha256LastSeenHeadCutToFortyCharactersExitsWithTwo(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        $seen = $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheCommandRuns(substr($seen, 0, 40), []);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('last seen head', $tester->getDisplay());
    }

    public function testASha256LengthLastSeenHeadInASha1RepositoryExitsWithTwo(): void
    {
        $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheCommandRuns(str_repeat('a', 64), []);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unicodeControls(): iterable
    {
        yield 'a next line' => ["\u{0085}", '\u{0085}'];
        yield 'a line separator' => ["\u{2028}", '\u{2028}'];
        yield 'a paragraph separator' => ["\u{2029}", '\u{2029}'];
        yield 'a right-to-left override' => ["\u{202E}", '\u{202E}'];
    }

    #[DataProvider('unicodeControls')]
    public function testAUnicodeControlInAReasonIsPrintedEscaped(string $control, string $escaped): void
    {
        $tester = $this->whenTheCommandRunsIn($this->repository->path.'/missing'.$control.'path', str_repeat('a', 40), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString($control, $tester->getDisplay());
        self::assertStringContainsString('missing'.$escaped.'path', $tester->getDisplay());
    }

    public function testAReasonThatIsNotUtf8HasItsHighBytesPrintedEscaped(): void
    {
        $tester = $this->whenTheCommandRunsIn($this->repository->path."/missing\xC2path", str_repeat('a', 40), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString("\xC2", $tester->getDisplay());
        self::assertStringContainsString('missing\302path', $tester->getDisplay());
    }

    public function testAPrintableUnicodeCharacterInAReasonIsKept(): void
    {
        $tester = $this->whenTheCommandRunsIn($this->repository->path.'/missing-Ä', str_repeat('a', 40), []);

        self::assertStringContainsString('missing-Ä', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recordedMergesThatAreNotARange(): iterable
    {
        yield 'no separator' => ['{head}'];
        yield 'an empty value' => [''];
        yield 'three dots' => ['{base}...{head}'];
        yield 'two separators' => ['{base}..{head}..{base}'];
        yield 'no base' => ['..{head}'];
        yield 'no head' => ['{base}..'];
        yield 'an abbreviated base' => ['{short}..{head}'];
        yield 'branch names' => ['main..task'];
        yield 'a space around the separator' => ['{base} ..{head}'];
        yield 'an uppercase name' => ['{upper}..{head}'];
    }

    #[DataProvider('recordedMergesThatAreNotARange')]
    public function testARecordedMergeThatIsNotTwoFullCommitNamesAroundTwoDotsExitsWithTwo(string $value): void
    {
        [$seen, $merge] = $this->seenCommitAndMergeOfATaskBranch();
        $range = strtr($value, ['{base}' => $seen, '{head}' => $merge, '{short}' => substr($seen, 0, 12), '{upper}' => strtoupper($seen)]);

        $tester = $this->whenTheCommandRuns($seen, ['--recorded-merge' => [$range]]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringNotContainsString('main is clean', $tester->getDisplay());
    }

    public function testARecordedMergeWithoutTheSeparatorNamesTheExpectedForm(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheCommandRuns($seen, ['--recorded-merge' => [$seen]]);

        self::assertStringContainsString('<base>..<head>', $tester->getDisplay());
    }

    public function testARecordedMergeWithAnAbbreviatedHeadNamesTheHead(): void
    {
        [$seen, $merge] = $this->seenCommitAndMergeOfATaskBranch();

        $tester = $this->whenTheCommandRuns($seen, ['--recorded-merge' => [$seen.'..'.substr($merge, 0, 7)]]);

        self::assertStringContainsString('recorded merge head', $tester->getDisplay());
    }

    public function testALastSeenHeadThatIsNotAFullCommitNameExitsWithTwo(): void
    {
        $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheCommandRuns('main', []);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('last seen head', $tester->getDisplay());
    }

    public function testAMalformedOwnerKeyExitsWithTwoWithoutEchoingTheKey(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        $tester = $this->whenTheCommandRuns($seen, ['--owner-key' => [$this->publicKey('owner'), 'cert-authority secret-looking-value']]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Owner key 2', $tester->getDisplay());
        self::assertStringNotContainsString('secret-looking-value', $tester->getDisplay());
    }

    public function testAShallowRepositoryExitsWithOneAndIsNeverReportedClean(): void
    {
        $this->repository->commitEmpty('Old');
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('New');
        $this->repository->cutHistoryBelow($seen);

        $tester = $this->whenTheCommandRuns($seen, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('shallow repository', $tester->getDisplay());
        self::assertStringNotContainsString('main is clean', $tester->getDisplay());
    }

    public function testARecordedHeadTheRepositoryDoesNotHoldExitsWithOne(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('New commit on main');

        $tester = $this->whenTheCommandRuns($seen, ['--recorded-merge' => [$seen.'..'.str_repeat('e', 40)]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Main was not read', $tester->getDisplay());
        self::assertStringNotContainsString('main is clean', $tester->getDisplay());
    }

    public function testAReasonWithALineBreakIsPrintedOnOneEscapedLine(): void
    {
        $tester = $this->whenTheCommandRunsIn($this->repository->path."/missing\nviolation", str_repeat('a', 40), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing\nviolation', $tester->getDisplay());
    }

    /**
     * @return array{string, string}
     */
    private function seenCommitAndMergeOfATaskBranch(): array
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->checkoutNewBranch('task');
        $this->repository->commitEmpty('Task commit');
        $this->repository->checkout('main');

        return [$seen, $this->repository->merge('task')];
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function whenTheCommandRuns(string $seen, array $options): CommandTester
    {
        return $this->whenTheCommandRunsIn($this->repository->path, $seen, $options);
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function whenTheCommandRunsIn(string $path, string $seen, array $options): CommandTester
    {
        $application = new Application();
        $application->addCommand(new CheckMainCommand(new CheckMain(new HardenedGit(), new Filesystem())));
        $tester = new CommandTester($application->find('intakt:watchdog:check'));
        $tester->execute(['repository' => $path, 'last-seen-head' => $seen, ...$options]);

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
