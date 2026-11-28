<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Core\Build\Contract\FinishedSplit;
use Intakt\Core\Build\Contract\FinishedSplits;
use Intakt\Core\Build\Contract\UmbrellaTasks;
use Intakt\Core\Build\Infrastructure\RecordedSplitUmbrellas;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubWorkSource\Domain\ClosedUmbrellas;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\GitHubWorkSource;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\UmbrellaClosing;
use Intakt\Plugin\GitHubWorkSource\UseCase\CloseUmbrellas\CloseUmbrellasCommand;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

// The command as an install on SQLite runs it: the project file read, the split recorded in the ledger as intakt:build:size records it, the open issues in GitHub's shape from a recording and each close kept instead of sent, so the parts can be closed one at a time.
#[CoversClass(CloseUmbrellasCommand::class)]
#[UsesClass(GitHubWorkSource::class)]
#[UsesClass(UmbrellaClosing::class)]
#[UsesClass(ClosedUmbrellas::class)]
#[UsesClass(RecordedSplitUmbrellas::class)]
#[UsesClass(UmbrellaTasks::class)]
#[UsesClass(FinishedSplit::class)]
#[UsesClass(FinishedSplits::class)]
#[UsesClass(KeyedComment::class)]
final class CloseUmbrellasOnTheContainerTest extends KernelTestCase
{
    private const array VARIABLES = ['INTAKT_CONFIG', 'BUILD_RECORDING'];
    private const string UMBRELLA = '{"number": 7, "title": "Close the umbrella", "body": "", "labels": [{"name": "intakt"}], "user": {"login": "maintainer"}, "updated_at": "2026-11-20T09:00:00+00:00", "milestone": null}';

    private string $file = '';
    private string $recording = '';

    /** @var array<string, mixed> */
    private array $saved = [];

    private ?CompiledContainers $containers = null;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testAnUmbrellaWhosePartsCloseOneByOneClosesAfterTheLast(): void
    {
        $kernel = $this->bootedWithASplitOf7Into201And202();

        $this->lists(self::UMBRELLA, $this->part(201), $this->part(202));
        $bothOpen = $this->closed($kernel);
        $this->lists(self::UMBRELLA, $this->part(202));
        $oneOpen = $this->closed($kernel);
        $this->lists(self::UMBRELLA);
        $noneOpen = $this->closed($kernel);
        $this->lists();
        $afterwards = $this->closed($kernel);

        self::assertSame("No umbrella has every part closed.\n", $bothOpen->getDisplay());
        self::assertSame("No umbrella has every part closed.\n", $oneOpen->getDisplay());
        self::assertSame("Closed umbrella #7: all parts are closed (#201, #202).\n", $noneOpen->getDisplay());
        self::assertSame("No umbrella has every part closed.\n", $afterwards->getDisplay());
        self::assertSame(Command::SUCCESS, $noneOpen->getStatusCode());
        self::assertSame([7], array_column($this->closes()->closed, 'number'));
        self::assertSame('All parts are closed (#201, #202), so this umbrella is closed.', $this->closes()->closed[0]['comment']->body);
    }

    public function testAShortListingClosesNothingAndFails(): void
    {
        $kernel = $this->bootedWithASplitOf7Into201And202();
        new Filesystem()->dumpFile($this->recording, '{"fails": "GitHub answered 502 to a listing."}');

        $tester = $this->closed($kernel);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The listing came back short, so no umbrella is closed', $tester->getErrorOutput());
        self::assertSame([], $this->closes()->closed);
    }

    public function testACloseGitHubRefusesFailsAndNamesTheUmbrellaThatStaysOpen(): void
    {
        $kernel = $this->bootedWithASplitOf7Into201And202();
        $this->closes()->refusal = new ForgeWriteFailed('GitHub answered 502 to a write.');
        $this->lists(self::UMBRELLA);

        $tester = $this->closed($kernel);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // Unwrapped, because the error block breaks its lines at the terminal's width.
        self::assertStringContainsString('Umbrella #7 has every part closed but stays open: GitHub answered 502 to a write.', (string) preg_replace('/\s+/', ' ', $tester->getErrorOutput()));
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();
        $this->saved = array_intersect_key($_SERVER, array_flip(self::VARIABLES));
        $name = bin2hex(random_bytes(4));
        $this->file = sys_get_temp_dir().'/intakt-umbrellas-close-'.$name.'.yaml';
        $this->recording = sys_get_temp_dir().'/intakt-umbrellas-close-'.$name.'.json';
    }

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $_SERVER = [...$_SERVER, ...$this->saved];

        parent::tearDown();
        $this->containers?->restoreTheSharedCache();
        new Filesystem()->remove([$this->file, $this->recording]);
    }

    // In the order the split records them, because the close reads the finished split and asks the filed parts by repository.
    private function bootedWithASplitOf7Into201And202(): KernelInterface
    {
        $contents = "projects:\n    alpha:\n        watch: { repository: repos/alpha, base: main, task: Review the pull request., github: acme/widget }\n";
        new Filesystem()->dumpFile($this->file, $contents);
        $this->lists();
        $_SERVER['INTAKT_CONFIG'] = $_ENV['INTAKT_CONFIG'] = $this->file;
        $_SERVER['BUILD_RECORDING'] = $_ENV['BUILD_RECORDING'] = $this->recording;
        $this->containers?->useFor([$contents]);
        $kernel = self::bootKernel();
        EmptyDatabase::rowsIn(self::getContainer());

        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        $events = [
            new LedgerEvent(LedgerEventKind::TaskSplitPlanned, '{"project":"alpha","task":"7","size":"split","run":"r1","parts":[],"labels":[]}', 'split-7'),
            new LedgerEvent(LedgerEventKind::PartIssueCreated, '{"project":"alpha","repository":"/repos/acme/widget","issue":201,"marker":"<!-- intakt:part k 1 -->"}', 'part-1'),
            new LedgerEvent(LedgerEventKind::PartIssueCreated, '{"project":"alpha","repository":"/repos/acme/widget","issue":202,"marker":"<!-- intakt:part k 2 -->"}', 'part-2'),
            new LedgerEvent(LedgerEventKind::TaskSplit, '{"project":"alpha","task":"7","run":"r1","parts":[201,202]}', 'split-7'),
        ];
        foreach ($events as $event) {
            self::assertTrue($ledger->append($event)->succeeded());
        }

        return $kernel;
    }

    private function part(int $number): string
    {
        return sprintf('{"number": %d, "title": "Part %d", "body": "", "labels": [{"name": "intakt"}], "user": {"login": "maintainer"}, "updated_at": "2026-11-20T09:01:00+00:00", "milestone": null}', $number, $number);
    }

    private function lists(string ...$issues): void
    {
        new Filesystem()->dumpFile($this->recording, '['.implode(",\n", $issues).']');
    }

    private function closed(KernelInterface $kernel): CommandTester
    {
        $tester = new CommandTester(new Application($kernel)->find('intakt:umbrellas:close'));
        $tester->execute(['project' => 'alpha'], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function closes(): RecordedTaskCloses
    {
        $closes = self::getContainer()->get('test.intakt.task_closes');
        self::assertInstanceOf(RecordedTaskCloses::class, $closes);

        return $closes;
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'umbrellas-close');
    }
}
