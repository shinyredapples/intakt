<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Platform\Contract\HeaderSpans;
use Intakt\Core\Platform\Contract\HeldCredentialSpans;
use Intakt\Core\Platform\Contract\ListedHeaders;
use Intakt\Core\Platform\Contract\RedactedSpans;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Review\Contract\ReviewTool;
use Intakt\Core\Review\Contract\ToolCallCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;

#[CoversClass(ReviewTool::class)]
#[CoversClass(ToolCallCount::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(HeldCredentialSpans::class)]
#[UsesClass(HeaderSpans::class)]
#[UsesClass(RedactedSpans::class)]
#[UsesClass(ListedHeaders::class)]
final class ReviewToolTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('calls')]
    public function testACallReadsInPlainWords(ReviewTool $tool, array $arguments, string $words): void
    {
        self::assertSame($words, $tool->callInWords(new ToolCall('call-1', $tool->value, $arguments)));
    }

    /**
     * @return iterable<string, array{ReviewTool, array<string, mixed>, string}>
     */
    public static function calls(): iterable
    {
        yield 'a file read' => [ReviewTool::ReadFile, ['path' => 'src/discount.js'], 'read src/discount.js'];
        yield 'a directory listed' => [ReviewTool::ListDirectory, ['path' => 'src/'], 'listed src/'];
        yield 'the root listed' => [ReviewTool::ListDirectory, ['path' => '.'], 'listed the repository root'];
        yield 'the root listed with a trailing slash' => [ReviewTool::ListDirectory, ['path' => './'], 'listed the repository root'];
        yield 'a search of the whole head with a trailing slash' => [ReviewTool::SearchFiles, ['text' => 'discount', 'path' => './'], 'searched for "discount"'];
        yield 'the root listed by default' => [ReviewTool::ListDirectory, [], 'listed the repository root'];
        yield 'a search in a directory' => [ReviewTool::SearchFiles, ['text' => 'discount', 'path' => 'src'], 'searched for "discount" in src'];
        yield 'a search of the whole head' => [ReviewTool::SearchFiles, ['text' => 'discount', 'path' => '.'], 'searched for "discount"'];
        yield 'a search by default of the whole head' => [ReviewTool::SearchFiles, ['text' => 'discount'], 'searched for "discount"'];
    }

    public function testASearchForACredentialReadsAsTheMarkerAndNeverHoldsIt(): void
    {
        $credential = 'github_pat_'.str_repeat('A', 82);

        $words = ReviewTool::SearchFiles->callInWords(new ToolCall('call-1', 'search_files', ['text' => $credential]));

        self::assertSame('searched for "[redacted]"', $words);
    }

    public function testAReadAndAListingOfAPathHoldingACredentialNeverHoldItAndASearchPathNeither(): void
    {
        $credential = 'ghp_'.str_repeat('B', 30);

        foreach (ReviewTool::cases() as $tool) {
            $words = $tool->callInWords(new ToolCall('call-1', $tool->value, ['path' => 'src/'.$credential, 'text' => 'x']));

            self::assertStringNotContainsString($credential, $words);
            self::assertStringContainsString('[redacted]', $words);
        }
    }

    #[DataProvider('tools')]
    public function testAPathWithACredentialAfterAnUnderscoreReadsAsTheMarker(ReviewTool $tool): void
    {
        $credential = 'github_pat_'.str_repeat('C', 82);

        $words = $tool->callInWords(new ToolCall('call-1', $tool->value, ['path' => 'fixtures/key_'.$credential.'.txt', 'text' => 'x']));

        self::assertStringNotContainsString($credential, $words);
        self::assertStringContainsString('fixtures/key_[redacted]', $words);
    }

    public function testAReadOfAPathHoldingTwoWebTokensJoinedByAHyphenReadsAsOneMarker(): void
    {
        $first = 'eyJ'.str_repeat('H', 12).'.eyJ'.str_repeat('P', 12).'.'.str_repeat('S', 16);
        $second = 'eyJ'.str_repeat('I', 12).'.eyJ'.str_repeat('Q', 12).'.'.str_repeat('T', 16);

        $words = ReviewTool::ReadFile->callInWords(new ToolCall('call-1', ReviewTool::ReadFile->value, ['path' => 'fixtures/key_'.$first.'-'.$second.'.txt']));

        self::assertSame('read fixtures/key_[redacted].txt', $words);
    }

    /**
     * @return iterable<string, array{ReviewTool}>
     */
    public static function tools(): iterable
    {
        foreach (ReviewTool::cases() as $tool) {
            yield $tool->value => [$tool];
        }
    }

    // The model writes the arguments, so a malformed one must still give a line and never the raw value.
    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('malformedCalls')]
    public function testACallWithAMalformedArgumentReadsAsTheCallWithoutIt(ReviewTool $tool, array $arguments, string $words): void
    {
        self::assertSame($words, $tool->callInWords(new ToolCall('call-1', $tool->value, $arguments)));
    }

    /**
     * @return iterable<string, array{ReviewTool, array<string, mixed>, string}>
     */
    public static function malformedCalls(): iterable
    {
        yield 'a read without a path' => [ReviewTool::ReadFile, [], 'read a file'];
        yield 'a read of a blank path' => [ReviewTool::ReadFile, ['path' => '  '], 'read a file'];
        yield 'a read of a path that is not text' => [ReviewTool::ReadFile, ['path' => ['src']], 'read a file'];
        yield 'a search without a text' => [ReviewTool::SearchFiles, ['path' => 'src'], 'searched the files in src'];
        yield 'a search of a number' => [ReviewTool::SearchFiles, ['text' => 7], 'searched the files'];
    }

    public function testEachKindTheReviewServerCountsReadsInPlainWords(): void
    {
        $kinds = array_map(static fn (ReviewTool $tool): string => new ToolCallCount($tool->value, 1)->kindInWords(), ReviewTool::cases());

        self::assertSame(['File reads', 'Directory listings', 'Searches'], $kinds);
    }

    public function testAKindNoReviewerToolHasReadsAsOtherToolCallsWithoutItsName(): void
    {
        self::assertSame('Other tool calls', new ToolCallCount('write_file', 1)->kindInWords());
    }
}
