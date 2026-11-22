<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

#[CoversClass(ReviewerAnswer::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
final class ReviewerAnswerTest extends TestCase
{
    // Taken from main before the answer document existed, because the schema the reviewers get must not change with it; only the gate_gap marker was added since, on purpose.
    private const string SCHEMA_FROM_MAIN = __DIR__.'/fixtures/verdict-schema.json';

    public function testTheVerdictJsonSchemaMatchesTheOneTakenFromMain(): void
    {
        $sent = new ResponseFormatFactory()->create(ReviewerAnswer::class);

        self::assertSame(json_decode((string) file_get_contents(self::SCHEMA_FROM_MAIN), true, flags: JSON_THROW_ON_ERROR), $sent);
    }

    public function testAnAnswerMapsToAVerdictWithTheSameDecisionFindingsResolvedAndFollowUps(): void
    {
        $finding = new Finding('a', Severity::P2, 'src/A.php', 7, null, 'Broken.', FixableBy::Builder, false, null, new Evidence('php -l', 'ok'));

        $verdict = new ReviewerAnswer(Decision::Changes, [$finding], ['b'], ['later'])->toVerdict();

        self::assertEquals(new Verdict(Decision::Changes, [$finding], [new ResolvedFinding('b')], [new FollowUpNote('later')]), $verdict);
    }

    public function testAnAnswerNamingAFailureIdStillClaimsItOnceMapped(): void
    {
        $finding = new Finding(Verdict::NO_TOOLS, Severity::P1, null, null, null, 'No tools.', FixableBy::Owner, false, null, null);

        $verdict = new ReviewerAnswer(Decision::Blocked, [$finding], [], [])->toVerdict();

        self::assertSame([Verdict::NO_TOOLS], [...$verdict->claimedFailureIds()]);
    }
}
