<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\PathGlob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocsPages::class)]
#[UsesClass(PathGlob::class)]
final class DocsPagesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'a user guide page' => ['docs/user/03-reviews.md'];
        yield 'a nested reference page' => ['docs/design/05-architecture/07-reviews.md'];
        yield 'the readme' => ['README.md'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNotCanonical(): iterable
    {
        yield 'a parent segment' => ['docs/../CONTRIBUTING.md'];
        yield 'a current segment' => ['docs/./user/a.md'];
        yield 'an empty segment' => ['docs//user/a.md'];
        yield 'a leading slash' => ['/docs/user/a.md'];
        yield 'a backslash' => ['docs\user\a.md'];
        yield 'a backslash that leaves the docs' => ['docs/user\..\..\CONTRIBUTING.md'];
        yield 'an empty path' => [''];
    }

    #[DataProvider('pages')]
    public function testAPageUnderTheDocsGlobsIsADocsPage(string $path): void
    {
        self::assertTrue(new DocsPages()->contains($path));
    }

    #[DataProvider('pathsThatAreNotCanonical')]
    public function testAPathThatIsNotCanonicalIsNoDocsPage(string $path): void
    {
        self::assertFalse(new DocsPages()->contains($path));
    }
}
