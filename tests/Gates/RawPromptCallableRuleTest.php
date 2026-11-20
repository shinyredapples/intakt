<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Gates\PhpStan\RawPromptCallableRule;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<RawPromptCallableRule>
 */
#[CoversClass(RawPromptCallableRule::class)]
#[UsesClass(RawPromptMessageRule::class)]
final class RawPromptCallableRuleTest extends PhpStanRuleTestCase
{
    // the fixtures' own classes, since a fixture cannot declare the repository's PromptAssembly or handler a second time
    private const string ASSEMBLY = 'Intakt\Sample\PromptAssembly';

    private const string HANDLER = 'Intakt\Sample\ReviewWithReviewerHandler';

    protected function ruleFor(string $root): RawPromptCallableRule
    {
        return new RawPromptCallableRule(new RawPromptMessageRule($root, self::ASSEMBLY, self::HANDLER, ReviewerConversation::class, RoundReviewer::class));
    }

    public function testAMessageFactoryTakenAsAFirstClassCallableOutsideTheAssemblyClassIsRefused(): void
    {
        $findings = $this->ruleFindingsOf('raw-prompt-first-class-callable');

        self::assertStringContainsString('src/Briefing.php:14 intakt.rawPromptMessage A model message built from a plain string outside Intakt\Sample\PromptAssembly', $findings);
        self::assertSame(1, substr_count($findings, 'intakt.rawPromptMessage'), $findings);
    }

    public function testTheAssemblyClassMayTakeAMessageFactoryAsAFirstClassCallable(): void
    {
        $this->assertRuleAccepts('raw-prompt-first-class-callable-in-the-assembly');
    }
}
