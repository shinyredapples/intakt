<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\UnstreamedCliConverter;
use Intakt\Core\Ai\Infrastructure\UnstreamedCliUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter as ClaudeCodeConverter;
use Symfony\AI\Platform\Bridge\Codex\ResultConverter as CodexConverter;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

#[CoversClass(UnstreamedCliConverter::class)]
#[CoversClass(UnstreamedCliUsage::class)]
final class UnstreamedCliConverterTest extends TestCase
{
    private const array STREAMED = ['stream' => true, 'response_format' => ['type' => 'json_schema']];

    public function testTheCodexBridgeAnswersInOnePieceThoughTheCallAskedForAStream(): void
    {
        $raw = new InMemoryRawResult(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => '{"verdict":"approve"}'], 'usage' => ['input_tokens' => 30, 'output_tokens' => 4]]);
        $converter = new UnstreamedCliConverter(new CodexConverter());

        $answer = $converter->convert($raw, self::STREAMED);
        $usage = $converter->getTokenUsageExtractor()?->extract($raw, self::STREAMED);

        self::assertInstanceOf(TextResult::class, $answer);
        self::assertSame('{"verdict":"approve"}', $answer->getContent());
        self::assertInstanceOf(TokenUsageInterface::class, $usage);
        self::assertSame([30, 4], [$usage->getPromptTokens(), $usage->getCompletionTokens()]);
    }

    public function testTheClaudeCodeBridgeAnswersInOnePieceThoughTheCallAskedForAStream(): void
    {
        $raw = new InMemoryRawResult(['type' => 'result', 'result' => '{"verdict":"approve"}', 'usage' => ['input_tokens' => 30, 'output_tokens' => 4]]);
        $converter = new UnstreamedCliConverter(new ClaudeCodeConverter());

        $answer = $converter->convert($raw, self::STREAMED);
        $usage = $converter->getTokenUsageExtractor()?->extract($raw, self::STREAMED);

        self::assertInstanceOf(TextResult::class, $answer);
        self::assertSame('{"verdict":"approve"}', $answer->getContent());
        self::assertInstanceOf(TokenUsageInterface::class, $usage);
        self::assertSame([30, 4], [$usage->getPromptTokens(), $usage->getCompletionTokens()]);
    }

    public function testTheOtherOptionsReachTheBridgeAsTheyAre(): void
    {
        $bridge = new RecordingConverter();

        new UnstreamedCliConverter($bridge)->convert(new InMemoryRawResult(), self::STREAMED);

        self::assertSame(['response_format' => ['type' => 'json_schema']], $bridge->options);
    }

    public function testACallThatAskedForNoStreamIsPassedOnUnchanged(): void
    {
        $bridge = new RecordingConverter();

        new UnstreamedCliConverter($bridge)->convert(new InMemoryRawResult(), ['response_format' => ['type' => 'json_schema']]);

        self::assertSame(['response_format' => ['type' => 'json_schema']], $bridge->options);
    }

    public function testABridgeWithoutAnExtractorKeepsNone(): void
    {
        $bridge = self::createStub(ResultConverterInterface::class);
        $bridge->method('getTokenUsageExtractor')->willReturn(null);

        self::assertNull(new UnstreamedCliConverter($bridge)->getTokenUsageExtractor());
    }
}
