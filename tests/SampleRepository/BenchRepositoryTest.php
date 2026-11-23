<?php

declare(strict_types=1);

namespace Intakt\Tests\SampleRepository;

use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\InvalidExpectations;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[CoversClass(Expectations::class)]
#[UsesClass(PlantedDefect::class)]
#[UsesClass(InvalidExpectations::class)]
final class BenchRepositoryTest extends TestCase
{
    private const string SAMPLE = __DIR__.'/../../fixtures/sample-repository';

    private ?TemporaryRepository $repository = null;

    public function testEveryBenchChangeBuildsOnTheBaseAsItsOwnBranch(): void
    {
        $repository = $this->build();

        foreach (self::changes() as $change) {
            self::assertNotSame('', $repository->log('--oneline', 'main..bench/'.$change), $change.' has no commits');
        }

        self::assertGreaterThanOrEqual(8, count(self::changes()));
    }

    public function testEveryChangeHasATaskAndReadableExpectations(): void
    {
        foreach (self::changes() as $change) {
            self::assertNotSame('', trim(new Filesystem()->readFile(self::SAMPLE.'/bench/'.$change.'/task.md')));
            self::expectationsOf($change);
        }
    }

    public function testAtLeastTwoBenchChangesPlantNoDefect(): void
    {
        $clean = array_filter(self::changes(), static fn (string $change): bool => self::expectationsOf($change)->isDefectFree());

        self::assertGreaterThanOrEqual(2, count($clean));
    }

    public function testThePlantedDefectsCoverTheFiveKindsTheBenchAsksFor(): void
    {
        $ids = [];
        foreach (self::changes() as $change) {
            foreach (self::expectationsOf($change)->defects as $defect) {
                $ids[] = $defect->id;
            }
        }

        $kinds = ['coupon-save10-missing', 'docs-state-missing-discount', 'export-name-runs-commands', 'free-shipping-boundary', 'price-list-error-path'];

        self::assertSame($kinds, array_values(array_intersect($kinds, $ids)));
        self::assertSame(array_unique($ids), $ids);
    }

    #[DataProvider('changeNames')]
    public function testEveryPlantedDefectLiesOnLinesTheChangeAdds(string $change): void
    {
        $added = $this->addedLines($this->build(), $change);

        foreach (self::expectationsOf($change)->defects as $defect) {
            for ($line = $defect->from; $line <= $defect->to; ++$line) {
                self::assertContains($line, $added[$defect->file] ?? [], $defect->id.' line '.$line.' is not an added line of '.$defect->file);
            }
        }
    }

    public function testAnUnchangedContextLineIsNotCountedAsAdded(): void
    {
        $added = $this->addedLines($this->build(), 'bulk-discount-docs');

        self::assertNotContains(1, $added['README.md'] ?? []);
        self::assertContains(5, $added['README.md'] ?? []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function changeNames(): array
    {
        $withDefects = array_filter(self::changes(), static fn (string $change): bool => !self::expectationsOf($change)->isDefectFree());

        return array_combine($withDefects, array_map(static fn (string $change): array => [$change], $withDefects));
    }

    protected function tearDown(): void
    {
        if ($this->repository instanceof TemporaryRepository) {
            new Filesystem()->remove($this->repository->path);
        }
    }

    private static function expectationsOf(string $change): Expectations
    {
        return Expectations::fromJson(new Filesystem()->readFile(self::SAMPLE.'/bench/'.$change.'/expectations.json'));
    }

    /**
     * @return array<string, list<int>>
     */
    private function addedLines(TemporaryRepository $repository, string $change): array
    {
        $added = [];
        foreach (explode("\ndiff --git ", "\n".$repository->diff('main', 'bench/'.$change)) as $fileDiff) {
            if (1 === preg_match('/^\+\+\+ b\/(.+)$/m', $fileDiff, $name)) {
                $added[$name[1]] = $this->addedInFile($fileDiff);
            }
        }

        return $added;
    }

    /**
     * @return list<int>
     */
    private function addedInFile(string $fileDiff): array
    {
        $added = [];
        foreach (array_slice(explode("\n@@ ", $fileDiff), 1) as $hunk) {
            $added = [...$added, ...$this->addedInHunk($hunk)];
        }

        return $added;
    }

    /**
     * @return list<int>
     */
    private function addedInHunk(string $hunk): array
    {
        preg_match('/^-\S+ \+(\d+)/', $hunk, $start);
        $next = (int) ($start[1] ?? 0);
        $added = [];
        foreach (array_slice(explode("\n", $hunk), 1) as $line) {
            if (str_starts_with($line, '+')) {
                $added[] = $next++;
            } elseif (str_starts_with($line, ' ')) {
                ++$next;
            }
        }

        return $added;
    }

    private function build(): TemporaryRepository
    {
        $repository = $this->repository = new TemporaryRepository();
        $this->commitTree($repository, self::SAMPLE.'/base');

        foreach (self::changes() as $change) {
            $repository->checkout('main');
            $repository->checkoutNewBranch('bench/'.$change);
            $this->commitTree($repository, self::SAMPLE.'/bench/'.$change.'/tree');
        }

        return $repository;
    }

    /**
     * @return list<string>
     */
    private static function changes(): array
    {
        $names = [];
        foreach (new Finder()->directories()->depth(0)->in(self::SAMPLE.'/bench')->sortByName() as $directory) {
            $names[] = $directory->getFilename();
        }

        return $names;
    }

    private function commitTree(TemporaryRepository $repository, string $directory): void
    {
        foreach (new Finder()->files()->in($directory) as $file) {
            $repository->commitFile($file->getRelativePathname(), $file->getContents());
        }
    }
}
