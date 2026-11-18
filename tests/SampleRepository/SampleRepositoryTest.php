<?php

declare(strict_types=1);

namespace Intakt\Tests\SampleRepository;

use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[CoversNothing]
final class SampleRepositoryTest extends TestCase
{
    private const string SAMPLE = __DIR__.'/../../fixtures/sample-repository';

    private ?TemporaryRepository $repository = null;

    public function testTheSampleBuildsIntoABaseAndAPullRequestBranch(): void
    {
        $repository = $this->build();

        $branches = $repository->log('--all', '--format=%D');

        self::assertStringContainsString('main', $branches);
        self::assertStringContainsString('sample-change', $branches);
        self::assertNotSame('', $repository->log('--oneline', 'main..sample-change'));
        self::assertStringNotContainsString('src/shipping.js', $repository->log('--name-only', '--format=', 'main'));
    }

    public function testTheBranchChangesFilesAgainstTheBase(): void
    {
        $diff = $this->build()->diff('main', 'sample-change');

        self::assertStringContainsString('+++ b/src/discount.js', $diff);
    }

    public function testTheMissingTestSampleChangesNoTestFile(): void
    {
        $changed = $this->changedFileNames($this->build());

        self::assertNotSame([], $changed);
        self::assertSame([], $this->testFiles($changed));
    }

    public function testATestFileWithANonAsciiNameCountsAsAChangedTestFile(): void
    {
        $repository = $this->build();
        $repository->commitFile('test/régression.test.js', 'export const shippingTest = true;');

        $changed = $this->changedFileNames($repository);

        self::assertSame(['test/régression.test.js'], $this->testFiles($changed));
    }

    public function testTheIssuesCoverABugAMissingTestAndASecuritySmell(): void
    {
        $kinds = array_unique(array_column(self::listedIssues(), 'kind'));
        sort($kinds);

        self::assertSame(['bug', 'missing-test', 'security'], $kinds);
    }

    /**
     * @param array{file: string, evidence: string} $issue
     */
    #[DataProvider('issues')]
    public function testEachListedIssueIsAnAddedLineOfTheChange(array $issue): void
    {
        $repository = $this->build();

        $diff = $repository->diff('main', 'sample-change');

        self::assertStringContainsString('+++ b/'.$issue['file'], $diff);
        self::assertStringContainsString("\n+".$this->lineContaining($issue), $diff);
        self::assertFileExists(self::SAMPLE.'/pull-request/'.$issue['file']);
    }

    /**
     * @return array<string, array{array{file: string, evidence: string}}>
     */
    public static function issues(): array
    {
        $issues = [];
        foreach (self::listedIssues() as $issue) {
            $issues[$issue['id']] = [$issue];
        }

        return $issues;
    }

    protected function tearDown(): void
    {
        if ($this->repository instanceof TemporaryRepository) {
            new Filesystem()->remove($this->repository->path);
        }
    }

    /**
     * @return list<array{id: string, kind: string, file: string, description: string, evidence: string}>
     */
    private static function listedIssues(): array
    {
        /** @var list<array{id: string, kind: string, file: string, description: string, evidence: string}> $issues */
        $issues = json_decode(new Filesystem()->readFile(self::SAMPLE.'/issues.json'), true, flags: JSON_THROW_ON_ERROR);

        return $issues;
    }

    /**
     * @param array{file: string, evidence: string} $issue
     */
    private function lineContaining(array $issue): string
    {
        foreach (explode("\n", new Filesystem()->readFile(self::SAMPLE.'/pull-request/'.$issue['file'])) as $line) {
            if (str_contains($line, $issue['evidence'])) {
                return $line;
            }
        }

        self::fail('The evidence of '.$issue['file'].' is not in the file.');
    }

    /**
     * @return list<string>
     */
    private function changedFileNames(TemporaryRepository $repository): array
    {
        // NUL-delimited, because git quotes a non-ASCII name otherwise and the quote hides its `test/` prefix.
        $output = $repository->log('--name-only', '--format=', '-z', 'main..sample-change');

        return array_values(array_filter(explode("\0", $output), static fn (string $name): bool => '' !== $name));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function testFiles(array $names): array
    {
        return array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'test/')));
    }

    private function build(): TemporaryRepository
    {
        $repository = $this->repository = new TemporaryRepository();

        $this->commitTree($repository, 'base');
        $repository->checkoutNewBranch('sample-change');
        $this->commitTree($repository, 'pull-request');

        return $repository;
    }

    private function commitTree(TemporaryRepository $repository, string $tree): void
    {
        foreach (new Finder()->files()->in(self::SAMPLE.'/'.$tree) as $file) {
            $repository->commitFile($file->getRelativePathname(), $file->getContents());
        }
    }
}
