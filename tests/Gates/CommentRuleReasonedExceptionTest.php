<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CommentRule;
use Intakt\Gates\DocblockRule;
use Intakt\Gates\LeftUndoneComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommentRule::class)]
#[UsesClass(DocblockRule::class)]
#[UsesClass(LeftUndoneComments::class)]
final class CommentRuleReasonedExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function reasonedExceptions(): iterable
    {
        yield 'a TODO naming its issue' => ['// TODO(#123): drop once the forge sends the total, because it is computed twice'];
        yield 'a FIXME naming its issue' => ['// FIXME(#7): the rounding needs the new money type'];
        yield 'an XXX in a hash comment' => ['# XXX(#9): keeps the old order until the import is gone'];
    }

    #[DataProvider('reasonedExceptions')]
    public function testAMarkerNamingItsIssueWithAReasonPasses(string $comment): void
    {
        self::assertSame([], new CommentRule()->violations($this->codeWith($comment)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function markersThatAreNotExceptions(): iterable
    {
        yield 'a hack naming an issue' => ['// HACK(#12): because the forge rounds'];
        yield 'an issue without a colon' => ['// TODO(#12) because the forge rounds'];
        yield 'an issue without a hash' => ['// TODO(12): because the forge rounds'];
        yield 'issue zero' => ['// TODO(#0): because the forge rounds'];
        yield 'a second bare marker' => ['// TODO(#12): because the forge rounds, FIXME later'];
    }

    #[DataProvider('markersThatAreNotExceptions')]
    public function testAnyOtherMarkerIsStillRejected(string $comment): void
    {
        $violations = new CommentRule()->violations($this->codeWith($comment));

        self::assertCount(1, $violations);
        self::assertStringContainsString('TODO comment', $violations[0]);
    }

    public function testAnExceptionStillHasToSayWhy(): void
    {
        $violations = new CommentRule()->violations($this->codeWith('// TODO(#12): drop the cache'));

        self::assertCount(1, $violations);
        self::assertStringContainsString('does not say why', $violations[0]);
    }

    private function codeWith(string $comment): string
    {
        return "<?php\n\n".$comment."\nfinal class Sample\n{\n}\n";
    }
}
