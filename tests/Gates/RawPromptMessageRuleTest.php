<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends PhpStanRuleTestCase<RawPromptMessageRule>
 */
#[CoversClass(RawPromptMessageRule::class)]
final class RawPromptMessageRuleTest extends PhpStanRuleTestCase
{
    // the fixtures' own classes, since a fixture cannot declare the repository's PromptAssembly or handler a second time
    private const string ASSEMBLY = 'Intakt\Sample\PromptAssembly';

    private const string HANDLER = 'Intakt\Sample\ReviewWithReviewerHandler';

    protected function ruleFor(string $root): RawPromptMessageRule
    {
        return new RawPromptMessageRule($root, self::ASSEMBLY, self::HANDLER, ReviewerConversation::class, RoundReviewer::class);
    }

    public function testAUserMessageBuiltFromAStringOutsideTheAssemblyClassIsRefused(): void
    {
        $this->assertRuleRejects('raw-prompt-user-message', 'src/Briefing.php:14 intakt.rawPromptMessage A model message built from a plain string outside Intakt\Sample\PromptAssembly: build it through PromptAssembly');
    }

    public function testASystemAndAnAssistantMessageBuiltOutsideTheAssemblyClassAreRefused(): void
    {
        $findings = $this->ruleFindingsOf('raw-prompt-system-and-assistant-message');

        self::assertStringContainsString('src/Briefing.php:15 intakt.rawPromptMessage', $findings);
        self::assertStringContainsString('src/Briefing.php:20 intakt.rawPromptMessage', $findings);
    }

    public function testConstructingAUserMessageOrASystemMessageOutsideTheAssemblyClassIsRefused(): void
    {
        $findings = $this->ruleFindingsOf('raw-prompt-constructed-messages');

        self::assertStringContainsString('src/Briefing.php:15 intakt.rawPromptMessage', $findings);
        self::assertStringContainsString('src/Briefing.php:20 intakt.rawPromptMessage', $findings);
    }

    public function testAMessageBuiltThroughAnAliasACallableAClassStringOrAnotherCaseIsRefused(): void
    {
        $findings = $this->ruleFindingsOf('raw-prompt-other-call-forms');

        self::assertSame([17, 22, 27, 34, 41, 46], $this->linesOf($findings));
    }

    public function testAMessageBuiltInAScriptOrThroughAFactoryNamedAtRuntimeIsRefused(): void
    {
        $findings = $this->ruleFindingsOf('raw-prompt-in-a-script');

        self::assertSame(2, substr_count($findings, 'bin/ask.php:10 intakt.rawPromptMessage'), $findings);
    }

    public function testTheAssemblyClassMayBuildMessages(): void
    {
        $this->assertRuleAccepts('raw-prompt-in-the-assembly');
    }

    public function testAMessageBuiltThroughTheAssemblyFromFencedTextAndTheRoleOfAConversationPasses(): void
    {
        $this->assertRuleAccepts('raw-prompt-through-fenced-text');
    }

    public function testAMessageBuiltInATestIsNotJudged(): void
    {
        $this->assertRuleAccepts('raw-prompt-message-in-a-test');
    }

    public function testAReviewerConversationInTheHandlerReadingTheRoundReviewersRolePromptPasses(): void
    {
        $this->assertRuleAccepts('raw-prompt-role-of-the-round-reviewer');
    }

    public function testAReviewerConversationInTheHandlerWithAnotherStringIsRefused(): void
    {
        $findings = $this->ruleFindingsOf('raw-prompt-role-from-another-string');

        self::assertStringContainsString('src/ReviewWithReviewerHandler.php:14 intakt.unprovenRolePrompt A ReviewerConversation in Intakt\Sample\ReviewWithReviewerHandler whose role prompt is not rolePrompt() of a Intakt\Core\Review\Domain\RoundReviewer', $findings);
        self::assertSame([14, 19], $this->linesOf($findings));
    }

    public function testAReviewerConversationInTheHandlerWithRolePromptOfAnotherTypeOrANullsafeCallIsRefused(): void
    {
        self::assertSame([14, 19], $this->linesOf($this->ruleFindingsOf('raw-prompt-role-from-another-role-prompt')));
    }

    public function testAReviewerConversationInTheHandlerWithUnpackedArgumentsIsRefused(): void
    {
        self::assertSame([17, 25], $this->linesOf($this->ruleFindingsOf('raw-prompt-role-unpacked')));
    }

    public function testAReviewerConversationOutsideTheHandlerIsNotJudgedByThisRule(): void
    {
        $this->assertRuleAccepts('raw-prompt-role-outside-the-handler');
    }

    /**
     * @return list<int>
     */
    private function linesOf(string $findings): array
    {
        preg_match_all('~^src/\S+\.php:(\d+) ~m', $findings, $lines);

        return array_map(intval(...), $lines[1]);
    }
}
