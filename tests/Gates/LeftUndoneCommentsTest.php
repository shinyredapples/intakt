<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\PhpComments;
use Intakt\Gates\ScopeFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeftUndoneComments::class)]
#[UsesClass(PhpComments::class)]
final class LeftUndoneCommentsTest extends TestCase
{
    public function testAMarkerBehindTenThousandNestedCommentMarkersIsReported(): void
    {
        $source = "<?php\n".str_repeat('// ', 10000)."TODO later\n";

        self::assertSame([2 => 'TODO'], new LeftUndoneComments()->markersOn($source, [2], new PhpComments()));
    }

    public function testAReasonedExceptionBehindTenThousandNestedCommentMarkersIsNotReported(): void
    {
        $source = "<?php\n".str_repeat('# ', 10000)."TODO(#12): drop once the forge sends the total\n";

        self::assertSame([], new LeftUndoneComments()->markersOn($source, [2], new PhpComments()));
    }

    public function testACommentThePatternEngineCannotReadFailsInsteadOfPassing(): void
    {
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            new LeftUndoneComments()->markersOn("<?php\n// TODO(#12): drop once the forge sends the total\n", [2], new PhpComments());
            self::fail('The comment passed although the pattern engine could not read it.');
        } catch (ScopeFailure $failure) {
            self::assertSame('cannot read a comment: Backtrack limit exhausted', $failure->getMessage());
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }
}
