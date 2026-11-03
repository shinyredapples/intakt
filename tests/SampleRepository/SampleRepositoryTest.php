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

    protected function tearDown(): void
    {
        if ($this->repository instanceof TemporaryRepository) {
            new Filesystem()->remove($this->repository->path);
        }
    }

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

    /**
     * @return list<array{id: string, kind: string, file: string, description: string, evidence: string}>
     */
    private static function listedIssues(): array
    {
        return json_decode(file_get_contents(self::SAMPLE.'/issues.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{file: string, evidence: string} $issue
     */
    private function lineContaining(array $issue): string
    {
        foreach (file(self::SAMPLE.'/pull-request/'.$issue['file'], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_contains($line, $issue['evidence'])) {
                return $line;
            }
        }

        self::fail('The evidence of '.$issue['file'].' is not in the file.');
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
