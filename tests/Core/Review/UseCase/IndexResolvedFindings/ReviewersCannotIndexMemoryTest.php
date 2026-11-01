<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\IndexResolvedFindings;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// Reviewers run from Infrastructure and the Contract, so the write side of memory must be named nowhere in them; only the use case that runs after a completed round may take it.
#[CoversNothing]
final class ReviewersCannotIndexMemoryTest extends TestCase
{
    public function testNoReviewerCodeMentionsTheIndexingSideOfMemory(): void
    {
        $mentions = [];

        foreach (['Infrastructure', 'Contract', 'Domain'] as $folder) {
            foreach ($this->phpFilesIn(__DIR__.'/../../../../../src/Core/Review/'.$folder) as $file) {
                if (str_contains((string) file_get_contents($file), 'MemoryIndex')) {
                    $mentions[] = $file;
                }
            }
        }

        self::assertSame([], $mentions);
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $folder): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder)) as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
