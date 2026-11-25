<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Infrastructure\NextLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(NextLinks::class)]
final class NextLinksTest extends TestCase
{
    private const string TARGET = '/repos/a/b/issues?state=open&per_page=100&page=1';

    /**
     * @return iterable<string, array{string}>
     */
    public static function anchorsOfThisPage(): iterable
    {
        yield 'empty reference' => ['""'];
        yield 'query of this request' => ['"?state=open&per_page=100&page=1"'];
        yield 'query of this request in another order' => ['"?page=1&state=open&per_page=100"'];
        yield 'path and query of this request' => ['"/repos/a/b/issues?state=open&per_page=100&page=1"'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anchorsNotProvablyThisPage(): iterable
    {
        yield 'another query' => ['"?state=open&per_page=100&page=3"'];
        yield 'a repeated parameter' => ['"?state=closed&state=open&per_page=100&page=1"'];
        yield 'another path' => ['"/repos/a/c/issues?state=open&per_page=100&page=1"'];
        yield 'the path without its query' => ['"/repos/a/b/issues"'];
        yield 'a fragment' => ['"#other"'];
        yield 'this query with a fragment' => ['"?state=open&per_page=100&page=1#top"'];
        yield 'a network-path reference' => ['"//api.github.com/repos/a/b/issues?state=open&per_page=100&page=1"'];
        yield 'an absolute reference to another resource' => ['"https://api.github.com/repositories/2/issues"'];
        yield 'an absolute reference naming this page' => ['"https://api.github.com/repos/a/b/issues?state=open&per_page=100&page=1"'];
        yield 'an encoded query' => ['"?state%3Dopen&per_page=100&page=1"'];
    }

    #[DataProvider('anchorsOfThisPage')]
    public function testANextRelationAnchoredToTheRequestedPageNamesTheNextPage(string $anchor): void
    {
        self::assertTrue(new NextLinks()->namesNext(['<?page=2>; rel=next; anchor='.$anchor], self::TARGET));
    }

    #[DataProvider('anchorsNotProvablyThisPage')]
    public function testANextRelationWithAnAnchorNotProvablyTheRequestedPageFailsAsAnUnreadableLink(string $anchor): void
    {
        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a Link header this forge cannot read.'));

        new NextLinks()->namesNext(['<?page=2>; rel=next; anchor='.$anchor], self::TARGET);
    }

    public function testAnAnchoredLinkWithoutANextRelationIsReadWhateverItsAnchor(): void
    {
        self::assertFalse(new NextLinks()->namesNext(['<https://api.github.com/repositories/2/issues>; rel=prev; anchor="https://api.github.com/repositories/2/issues"'], self::TARGET));
    }

    public function testAnAnchoredNextToAnotherResourceAfterAPlainNextStillFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        new NextLinks()->namesNext(['<?page=2>; rel=next', '<?page=9>; rel=next; anchor="#other"'], self::TARGET);
    }

    public function testAPcreFailureWhileReadingLinkParametersFailsTheReadInsteadOfNamingNoNextPage(): void
    {
        $jit = ini_set('pcre.jit', '0');
        $limit = ini_set('pcre.backtrack_limit', '1');

        try {
            $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a Link header this forge cannot read.'));

            // The parameter matcher is called alone, because the whole-header match fails first through namesNext().
            new ReflectionMethod(NextLinks::class, 'relationsIn')->invoke(new NextLinks(), '; title="a b c d"; rel=next', self::TARGET);
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function testAnAnchorWithAControlCharacterInItsPathFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        new NextLinks()->namesNext(["<?page=2>; rel=next; anchor=\"/repos/a/b\tissues?state=open&per_page=100&page=1\""], '/repos/a/b_issues?state=open&per_page=100&page=1');
    }

    public function testAnAnchorWithoutAValueFailsAsAnUnreadableLink(): void
    {
        $this->expectException(ForgeReadFailed::class);

        new NextLinks()->namesNext(['<?page=2>; rel=next; anchor'], self::TARGET);
    }

    public function testARelWithoutAValueFailsAsAnUnreadableLink(): void
    {
        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a Link header this forge cannot read.'));

        new NextLinks()->namesNext(['</pulls?page=2>; rel'], '/pulls?page=1');
    }

    public function testALinkWithoutAnyRelIsReadAsNamingNoNextPage(): void
    {
        self::assertFalse(new NextLinks()->namesNext(['</pulls?page=2>; title="two"'], '/pulls?page=1'));
    }
}
