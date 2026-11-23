<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Domain\ChangeFiles;
use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\BenchChangeCheckout;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\BenchChangeNotFound;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\VerdictIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(BenchChangeCheckout::class)]
#[CoversClass(BenchChangeNotFound::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(Expectations::class)]
#[UsesClass(PlantedDefect::class)]
#[UsesClass(ChangeFiles::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(ChangeReviewRequest::class)]
#[UsesClass(RequestedRound::class)]
#[UsesClass(ReviewTerms::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
final class BenchChangeCheckoutTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../../fixtures/sample-repository';

    private string $directory;

    public function testTheRequestCarriesTheTaskTheDiffAndACheckoutOfTheChange(): void
    {
        $change = $this->checkout()->open('free-shipping', 'alpha', $this->directory);

        self::assertSame($this->directory, $change->request->checkoutRoot);
        self::assertSame(trim(new Filesystem()->readFile(self::FIXTURES.'/bench/free-shipping/task.md')), trim($change->request->task));
        self::assertStringContainsString('+++ b/src/shipping.js', $change->request->diff);
        self::assertFileExists($this->directory.'/src/shipping.js');
        self::assertFileExists($this->directory.'/src/cart.js');
    }

    public function testTheExpectationsAndTheFilesOfTheChangeAreRead(): void
    {
        $change = $this->checkout()->open('free-shipping', 'alpha', $this->directory);

        self::assertSame('free-shipping-boundary', $change->expectations->defects[0]->id);
        self::assertTrue($change->files->holdsRangeOf($change->expectations->defects[0]));
    }

    public function testADotfileOfTheChangeGetsItsLineCountLikeTheCheckoutCopiesIt(): void
    {
        $fixtures = $this->directory.'-fixtures';
        $filesystem = new Filesystem();
        $filesystem->mirror(self::FIXTURES, $fixtures);
        $filesystem->dumpFile($fixtures.'/bench/free-shipping/tree/.hidden', "one\ntwo\nthree\n");

        try {
            $files = $this->checkout($fixtures)->open('free-shipping', 'alpha', $this->directory)->files;
        } finally {
            $filesystem->remove($fixtures);
        }

        self::assertFileExists($this->directory.'/.hidden');
        self::assertTrue($files->holdsRangeOf(new PlantedDefect('hidden', '.hidden', 3, 3, 'x', Severity::P2)));
        self::assertFalse($files->holdsRangeOf(new PlantedDefect('hidden', '.hidden', 3, 4, 'x', Severity::P2)));
    }

    public function testTwoOpeningsOfOneChangeNameTheirRoundsApart(): void
    {
        $first = $this->checkout()->open('free-shipping', 'alpha', $this->directory);
        $second = $this->checkout()->open('free-shipping', 'alpha', $this->directory.'-again');
        new Filesystem()->remove($this->directory.'-again');

        self::assertNotSame($first->request->round->change, $second->request->round->change);
        self::assertTrue($first->request->identity->commits->head->equals($second->request->identity->commits->head));
    }

    #[DataProvider('unknownNames')]
    public function testAnUnknownOrUnsafeNameIsRefusedBeforeAnythingIsBuilt(string $name): void
    {
        $this->expectException(BenchChangeNotFound::class);

        try {
            $this->checkout()->open($name, 'alpha', $this->directory);
        } finally {
            self::assertDirectoryDoesNotExist($this->directory);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownNames(): array
    {
        return ['missing' => ['no-such-change'], 'parent folder' => ['../base'], 'empty' => ['']];
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-checkout-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function checkout(string $fixtures = self::FIXTURES): BenchChangeCheckout
    {
        $panel = new Panel([new PanelReviewer(new ReviewerRole('role', 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1)], QuorumPolicy::All);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($panel);

        return new BenchChangeCheckout($panels, $fixtures);
    }
}
