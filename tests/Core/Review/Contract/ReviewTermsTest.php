<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewTerms::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(QuorumPolicy::class)]
final class ReviewTermsTest extends TestCase
{
    public function testTheTermsHoldHashesOfTheTaskTheCheckCommandAndThePanelInsteadOfTheirTexts(): void
    {
        $terms = ReviewTerms::of('Add the thing.', 'bin/check', $this->panel('Review the change.'));

        self::assertSame(hash('sha256', 'Add the thing.'), $terms->taskRevision);
        self::assertSame(hash('sha256', 'bin/check'), $terms->checkCommand);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $terms->panelPolicy);
    }

    #[TestWith(['Add the thing.'], 'a task text')]
    #[TestWith(['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'], 'an uppercase hash')]
    public function testATermThatIsNoLowercaseSha256HashIsRefused(string $term): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReviewTerms($term, hash('sha256', 'bin/check'), hash('sha256', 'panel'));
    }

    public function testTheTermsDescribeTheTaskAndPanelTheyWereMadeOf(): void
    {
        $terms = ReviewTerms::of('Add the thing.', 'bin/check', $this->panel('Review the change.'));

        self::assertTrue($terms->describes('Add the thing.', $this->panel('Review the change.')));
    }

    public function testTheTermsDoNotDescribeAnotherTaskOrAnotherPanel(): void
    {
        $terms = ReviewTerms::of('Add the thing.', 'bin/check', $this->panel('Review the change.'));

        self::assertFalse($terms->describes('Add another thing.', $this->panel('Review the change.')));
        self::assertFalse($terms->describes('Add the thing.', $this->panel('Review the change for security.')));
    }

    /**
     * @param non-empty-string $prompt
     */
    private function panel(string $prompt): Panel
    {
        return new Panel([new PanelReviewer(new ReviewerRole('core', $prompt), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10)], QuorumPolicy::All);
    }
}
