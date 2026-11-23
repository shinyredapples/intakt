<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Filesystem\Filesystem;

// The label as the container hands it to the work source, so a project file whose label no longer arrives is seen by a test and not first by a loop that builds the other loop's tasks.
#[CoversNothing]
final class EligibilityLabelOnTheContainerTest extends KernelTestCase
{
    private string $file = '';

    private mixed $savedConfig = null;

    private ?CompiledContainers $containers = null;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testWithTheDefaultLabelATaskCarryingOnlyReadyIsNotEligible(): void
    {
        $this->bootWith('');

        self::assertSame([], $this->readyOf(Issues::task(1, ['ready'])));
    }

    public function testWithTheDefaultLabelATaskCarryingIntaktIsEligible(): void
    {
        $this->bootWith('');

        self::assertSame([1], $this->readyOf(Issues::task(1, ['intakt'])));
    }

    public function testWithAProjectLabelATaskCarryingOnlyReadyIsNotEligible(): void
    {
        $this->bootWith(', eligibility_label: build');

        self::assertSame([], $this->readyOf(Issues::task(1, ['ready'])));
    }

    public function testWithAProjectLabelATaskCarryingThatLabelIsEligible(): void
    {
        $this->bootWith(', eligibility_label: build');

        self::assertSame([2], $this->readyOf(Issues::task(1, ['intakt']), Issues::task(2, ['build'])));
    }

    public function testTearingDownHandsTheFollowingKernelTestsTheSharedCacheBack(): void
    {
        $sharedCache = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->bootWith(', eligibility_label: build');

        $this->tearDown();

        self::assertSame($sharedCache, $_SERVER['APP_CACHE_DIR'] ?? null);
    }

    protected function setUp(): void
    {
        // Taken before any boot points the cache elsewhere, so tearing down restores the folder the other kernel tests use.
        $this->containers = self::compiledContainers();
        $this->savedConfig = $_SERVER['INTAKT_CONFIG'] ?? null;
        $this->file = sys_get_temp_dir().'/intakt-eligibility-label-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_CONFIG']);
        if (null !== $this->savedConfig) {
            $_SERVER['INTAKT_CONFIG'] = $this->savedConfig;
        }

        parent::tearDown();
        $this->containers?->restoreTheSharedCache();
        new Filesystem()->remove($this->file);
    }

    private function bootWith(string $watchExtra): void
    {
        $contents = "projects:\n    alpha:\n        watch: { repository: repos/alpha, base: main, task: Review the pull request.".$watchExtra." }\n";
        new Filesystem()->dumpFile($this->file, $contents);
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        // Keyed by the content, because the path changes per test while the content is the only build input this class sets.
        $this->containers?->useFor([$contents]);
        self::bootKernel();
    }

    // Each task written, last edited and labeled by a writer, so only the label decides whether it is eligible.
    /**
     * @return list<int>
     */
    private function readyOf(ListedIssue ...$listed): array
    {
        $labels = self::getContainer()->get('test.intakt.eligibility_labels');
        self::assertInstanceOf(EligibilityLabels::class, $labels);
        $rules = new IntakeRules($labels->of('alpha'));

        return array_map(static fn (QueuedIssue $row): int => $row->number(), $rules->planOf(array_values(array_map($this->vouched(...), $listed)), [])->ready);
    }

    private function vouched(ListedIssue $listed): QueuedIssue
    {
        $references = new IssueReferences();
        $history = new IssueHistory($listed->author, array_map(static fn (string $label): LabelApplication => new LabelApplication($label, $listed->author), $listed->labels), '', $listed->labels);

        return new QueuedIssue(new ItemReading($listed, $references->waitsFor($listed->body), $references->unreadableWaits($listed->body), $references->closedBy($listed->body)))
            ->vouchedBy(Vouching::of($listed->author, $history, static fn (string $account): bool => 'ana' === $account));
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'eligibility-label');
    }
}
