<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Infrastructure\NextLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NextLinks::class)]
final class NextLinksTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function anchors(): iterable
    {
        yield 'empty reference' => ['""', true];
        yield 'query of this request' => ['"?page=1"', true];
        yield 'path and query of this request' => ['"/repos/a/b/issues?page=1"', true];
        yield 'another query' => ['"?page=3"', false];
        yield 'another path' => ['"/repos/a/c/issues?page=1"', false];
        yield 'a fragment' => ['"#other"', false];
        yield 'an absolute reference' => ['"https://api.github.com/repos/a/b/issues?page=1"', false];
    }

    #[DataProvider('anchors')]
    public function testAnAnchorCountsOnlyWhenItNamesTheRequestedPage(string $anchor, bool $expected): void
    {
        self::assertSame($expected, (new NextLinks())->namesNext(['<?page=2>; rel=next; anchor='.$anchor], '/repos/a/b/issues?page=1'));
    }

    public function testAnAnchorWithoutAValueFailsAsAnUnreadableLink(): void
    {
        $this->expectException(ForgeReadFailed::class);

        (new NextLinks())->namesNext(['<?page=2>; rel=next; anchor'], '/repos/a/b/issues?page=1');
    }
}
