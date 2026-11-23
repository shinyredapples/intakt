<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The review page's own sheet is read as text, as TaskDetailStylesTest reads the shared one, because no page is needed to see a rule.
#[CoversNothing]
final class ReviewPageStylesTest extends TestCase
{
    // Because DESIGN.md defines a list row as a title and one line of explanation.
    public function testAToFixCardsExplanationIsCutToOneLine(): void
    {
        $rules = $this->rulesOf($this->sheet('review.css'));

        self::assertMatchesRegularExpression('/-webkit-line-clamp:\s*1;\s*line-clamp:\s*1;\s*overflow:\s*hidden;/', $rules['.fix-more'] ?? '');
    }

    // The change id is a fact that is itself mono, so a rule on a mono span inside a fact alone would leave it on one line at 390 px.
    public function testAMetaFactThatIsItselfMonoWrapsAsWellAsAMonoSpanInsideAFact(): void
    {
        $rules = $this->rulesOf($this->sheet('review.css'));

        self::assertMatchesRegularExpression('/white-space:\s*normal;\s*overflow-wrap:\s*anywhere;/', $rules['.page-head .meta > span.mono, .page-head .meta > span .mono, .page-head .meta #review-summary-cost'] ?? '');
    }

    // DESIGN.md, Long titles: components.css lets every fact of the head's meta line wrap, and the page's own sheet must not undo it.
    public function testNoMetaFactOfTheReviewPageIsKeptOnOneLine(): void
    {
        $page = $this->rulesOf($this->sheet('review.css'));
        $shared = $this->rulesOf($this->sheet('components.css'));

        self::assertSame([], array_filter($page, static fn (string $body, string $selector): bool => str_contains($selector, '.meta') && str_contains($body, 'nowrap'), ARRAY_FILTER_USE_BOTH));
        self::assertMatchesRegularExpression('/white-space:\s*normal;\s*overflow-wrap:\s*anywhere;/', $shared['.page-head .meta span'] ?? '');
    }

    // A fixed gap per segment grows with the findings: 132 findings at 3 px need 393 px, wider than a 390 px phone, so the gaps take at most half the bar whatever the count.
    public function testTheUrgencyBarsGapsShareHalfItsWidthAtMostWhateverTheSegmentCount(): void
    {
        $rules = $this->rulesOf($this->sheet('review.css'));

        self::assertMatchesRegularExpression('~gap:\s*min\(3px,\s*calc\(50%\s*/\s*var\(--n,\s*1\)\)\);~', $rules['.urgency-bar'] ?? '');
        self::assertDoesNotMatchRegularExpression('/gap:\s*\d+px;/', $rules['.urgency-bar'] ?? '');
    }

    private function sheet(string $name): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/'.$name);
        self::assertIsString($sheet);

        return $sheet;
    }

    /**
     * @return array<string, string>
     */
    private function rulesOf(string $sheet): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $rule): array => [trim($rule[1]), $rule[2]], $rules), 1, 0);
    }
}
