<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\Infrastructure\DocsPartOfDiff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocsPartOfDiff::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(DocsPages::class)]
#[UsesClass(PathGlob::class)]
final class DocsPartOfDiffTest extends TestCase
{
    private const string CODE = <<<'DIFF'
        diff --git a/src/Cart.php b/src/Cart.php
        --- a/src/Cart.php
        +++ b/src/Cart.php
        @@ -1 +1 @@
        -old code
        +new code

        DIFF;

    private const string PAGE = <<<'DIFF'
        diff --git a/docs/user/cart.md b/docs/user/cart.md
        --- a/docs/user/cart.md
        +++ b/docs/user/cart.md
        @@ -1 +1 @@
        -Old words.
        +New words.

        DIFF;

    private const string README = <<<'DIFF'
        diff --git a/README.md b/README.md
        new file mode 100644
        --- /dev/null
        +++ b/README.md
        @@ -0,0 +1 @@
        +Hello.

        DIFF;

    public function testItKeepsOnlyThePagesPeopleRead(): void
    {
        self::assertSame(self::PAGE.self::README, new DocsPartOfDiff()->of(self::CODE.self::PAGE.self::CODE.self::README));
    }

    public function testADiffOfCodeOnlyHasNoDocsPart(): void
    {
        self::assertSame('', new DocsPartOfDiff()->of(self::CODE));
    }

    public function testAHunkLineThatLooksLikeAHeaderStaysInItsFile(): void
    {
        $page = str_replace('+New words.', "+New words.\n diff --git a/src/X.php b/src/X.php", self::PAGE);

        self::assertSame($page, new DocsPartOfDiff()->of(self::CODE.$page));
    }

    public function testAPageMovedOutOfTheDocsStaysInView(): void
    {
        $moved = "diff --git a/docs/user/old.md b/notes/old.md\nsimilarity index 100%\nrename from docs/user/old.md\nrename to notes/old.md\n";

        self::assertSame($moved, new DocsPartOfDiff()->of(self::CODE.$moved));
    }
}
