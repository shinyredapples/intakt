<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\MainVerdict;
use Intakt\Core\Watchdog\Contract\OwnerKeys;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Domain\SeenCommit;
use Intakt\Core\Watchdog\Domain\SignatureStatus;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainCommand;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainInput;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Watchdog\SigningKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CheckMainCommand::class)]
#[UsesClass(CheckMain::class)]
#[UsesClass(CheckMainInput::class)]
#[UsesClass(MainHistory::class)]
#[UsesClass(CommitClassifier::class)]
#[UsesClass(SignatureStatus::class)]
#[UsesClass(SeenCommit::class)]
#[UsesClass(Violation::class)]
#[UsesClass(MainVerdict::class)]
#[UsesClass(WatchedMain::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(OwnerKeys::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class CheckMainCommandTest extends TestCase
{
    private TemporaryRepository $repository;

    private SigningKeys $keys;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitEmpty('Base');
        $this->keys = new SigningKeys();
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
        $this->keys->remove();
    }

    public function testAnUnsignedCommitIsReportedAndFails(): void
    {
        $unsigned = $this->repository->commitEmpty('Straight to main');

        $tester = $this->whenRunWith(['--owner-key' => [$this->keys->publicKeyFileOf('owner')]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('unsigned '.$unsigned, $tester->getDisplay());
        self::assertStringContainsString('main is violated', $tester->getDisplay());
    }

    public function testAnOwnerSignedCommitLeavesMainClean(): void
    {
        $this->repository->commitEmptySignedWith($this->keys->privateKeyOf('owner'), 'Owner push');
        $since = $this->repository->objectAt('HEAD~1');

        $tester = $this->whenRunWith(['--since' => $since, '--owner-key' => [$this->keys->publicKeyFileOf('owner')]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('main is clean', $tester->getDisplay());
    }

    public function testACommitARecordedMergeBroughtToMainLeavesMainClean(): void
    {
        $base = $this->repository->objectAt('HEAD');
        $head = $this->repository->commitEmpty('Task');

        $tester = $this->whenRunWith(['--since' => $base, '--recorded-merge' => [$base.'..'.$head]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('main is clean', $tester->getDisplay());
    }

    public function testARecordedMergeThatIsNoRangeOfTwoCommitNamesIsRefused(): void
    {
        $head = $this->repository->commitEmpty('Task');

        $tester = $this->whenRunWith(['--recorded-merge' => [$head]]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('<base>..<head>', $tester->getDisplay());
    }

    public function testAKeyFileThatCannotBeReadIsRefused(): void
    {
        $tester = $this->whenRunWith(['--owner-key' => [$this->keys->directory.'/missing.pub']]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('cannot be read', $tester->getDisplay());
    }

    public function testARepositoryThatDoesNotExistIsRefused(): void
    {
        $tester = $this->whenRunWith([], $this->keys->directory.'/missing.git');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function whenRunWith(array $options, ?string $repository = null): CommandTester
    {
        $check = new CheckMain(new MainHistory(new HardenedGit(), new Filesystem()), new CommitClassifier());
        $application = new Application();
        $application->addCommand(new CheckMainCommand($check));
        $tester = new CommandTester($application->find('intakt:watchdog:check'));
        $tester->execute(['repository' => $repository ?? $this->repository->path.'/.git', ...$options]);

        return $tester;
    }
}
