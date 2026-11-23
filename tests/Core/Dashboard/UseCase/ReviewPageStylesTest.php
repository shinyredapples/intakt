<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The review page's own sheet is read as text, as TaskDetailStylesTest reads the shared one, because no page is needed to see a rule.
#[CoversNothing]
final class ReviewPageStylesTest extends TestCase
{
    // The owner's decision for #3573: the three urgency groups side by side on a desktop and stacked on a phone, so the rule sits only in the wide block.
    public function testTheFindingGroupsStandSideBySideOnlyOnAWideScreen(): void
    {
        $sheet = $this->sheet('review.css');
        preg_match('/@media \(min-width: 1024px\) \{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/', $sheet, $wide);

        self::assertMatchesRegularExpression('/grid-template-columns:\s*repeat\(3, minmax\(0, 1fr\)\);/', $this->rulesOf($wide[1] ?? '')['.to-fix'] ?? '');
        self::assertDoesNotMatchRegularExpression('/grid-template-columns/', $this->rulesOf($this->outsideMediaQueries($sheet))['.to-fix'] ?? '');
    }

    // A long path must wrap inside its column instead of widening the group at 390 px.
    public function testARowsPlaceTakesItsOwnLineAndWraps(): void
    {
        $rules = $this->rulesOf($this->sheet('review.css'));

        self::assertMatchesRegularExpression('/flex-basis:\s*100%;.*overflow-wrap:\s*anywhere;/', $rules['.fix-place'] ?? '');
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

    // A zero basis let a long reviewer label take the whole row on a desktop, so the verdict column asks for 14rem and the reviewers wrap to their own row when both do not fit.
    public function testOnADesktopTheVerdictColumnAsksForAWidthTheReviewerFacesCannotSqueeze(): void
    {
        $rules = $this->rulesOf($this->outsideMediaQueries($this->sheet('review.css')));

        self::assertMatchesRegularExpression('/flex:\s*1 1 14rem;/', $rules['.verdict-card .verdict-text'] ?? '');
        self::assertMatchesRegularExpression('/min-width:\s*0;/', $rules['.verdict-card .verdict-text'] ?? '');
        self::assertMatchesRegularExpression('/min-width:\s*0;/', $rules['.verdict-reviewers'] ?? '');
    }

    // Media queries in px do not follow the browser's font size: at a 32 px default font 14rem is 448 px, and with the 96 px owl and the gap the two columns need more than the card has at an 800 px viewport with the rail expanded, so the card wraps at every width and the column can shrink.
    public function testAtLargerTextTheVerdictCardWrapsAndItsVerdictColumnShrinksInsteadOfOverflowing(): void
    {
        $rules = $this->rulesOf($this->outsideMediaQueries($this->sheet('review.css')));

        self::assertMatchesRegularExpression('/flex-wrap:\s*wrap;/', $rules['.verdict-card'] ?? '');
        self::assertDoesNotMatchRegularExpression('/flex:\s*\d+ 0 /', $rules['.verdict-card .verdict-text'] ?? '');
    }

    // Below 600 px the reviewers sit on their own row, so the verdict column there shares the row with the owl alone as before.
    public function testOnAPhoneTheVerdictColumnGivesUpItsReservedWidth(): void
    {
        $rules = $this->rulesOf($this->phoneBlock($this->sheet('review.css')));

        self::assertMatchesRegularExpression('/flex:\s*1 1 0;/', $rules['.verdict-card .verdict-text'] ?? '');
        self::assertMatchesRegularExpression('/width:\s*100%;/', $rules['.verdict-reviewers'] ?? '');
    }

    private function outsideMediaQueries(string $sheet): string
    {
        return (string) preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $sheet);
    }

    private function phoneBlock(string $sheet): string
    {
        preg_match('/@media \(max-width: 599px\) \{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/', $sheet, $block);

        return $block[1] ?? '';
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
