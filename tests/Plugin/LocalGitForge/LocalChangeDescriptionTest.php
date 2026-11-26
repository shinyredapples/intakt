<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Contract\SummarySection;
use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalChangeDescription;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalChangeDescription::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(DocsPages::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Paths::class)]
#[UsesClass(PathGlob::class)]
#[UsesClass(SummarySection::class)]
#[UsesClass(BinaryFiles::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(FileContent::class)]
#[UsesClass(FileStatus::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(NameStatusParser::class)]
final class LocalChangeDescriptionTest extends TestCase
{
    private const string PAGE = "# Ledger\n\n## In short\n\nThe ledger keeps every event.\n\n## Details\n\nRows are appended.\n";

    private TemporaryRepository $repository;

    public function testItNamesThePathsAndTheChangedLines(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/Cart.php', "<?php\n");

        $description = $this->described();

        self::assertSame(['src/Cart.php'], [...$description->paths]);
        self::assertSame(1, $description->lines);
        self::assertSame([], [...$description->summaries]);
    }

    public function testAChangedLineInASummaryNamesItsPage(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('docs/architecture/ledger.md', str_replace('every event', 'each event', self::PAGE));

        self::assertSame(['docs/architecture/ledger.md'], [...$this->described()->summaries]);
    }

    public function testAChangedLineOutsideTheSummaryNamesNoPage(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('docs/architecture/ledger.md', str_replace('Rows are appended', 'Rows are added', self::PAGE));

        self::assertSame([], [...$this->described()->summaries]);
    }

    public function testADeletedPageWithASummaryNamesIt(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->removeFile('docs/architecture/ledger.md');

        self::assertSame(['docs/architecture/ledger.md'], [...$this->described()->summaries]);
    }

    public function testAnAddedPageWithASummaryNamesIt(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('docs/architecture/journeys.md', self::PAGE);

        self::assertSame(['docs/architecture/journeys.md'], [...$this->described()->summaries]);
    }

    public function testASummaryOutsideTheDocsPagesIsNotRead(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('notes/ledger.md', self::PAGE);

        self::assertSame([], [...$this->described()->summaries]);
    }

    public function testTheLabelsOfTheChangeReachItsDescription(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('docs/reference/cart.md', "# Cart\n");

        self::assertTrue($this->described(new Labels(ChangeDescription::TECHNICAL_WRITER_LABEL))->asksForTechnicalWriter());
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('docs/architecture/ledger.md', self::PAGE);
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function described(Labels $labels = new Labels()): ChangeDescription
    {
        $forge = new LocalGitForge(new LocalRepository($this->repository->path, 'main'), new NameStatusParser());

        return new LocalChangeDescription()->of($forge, $forge->change(new ChangeId('feature')), $labels);
    }
}
