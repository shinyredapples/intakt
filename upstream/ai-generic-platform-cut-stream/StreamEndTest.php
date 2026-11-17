<?php

declare(strict_types=1);

namespace Intakt\Upstream\GenericPlatformCutStream;

use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

// The chunks follow the OpenAI chat completions stream format that the Hugging Face router documents; they are written by hand, not recorded from a live provider.
#[CoversNothing]
final class StreamEndTest extends TestCase
{
    private const string MODEL = 'openai/gpt-oss-120b:novita';

    private const string DONE = "data: [DONE]\n\n";

    public function testACompleteStreamEndingWithAUsageOnlyChunkAndDoneYieldsItsText(): void
    {
        $text = $this->textOf([$this->opening(), $this->reasoning(), $this->content(), $this->finish(), $this->usage(), self::DONE]);

        self::assertSame('{"verdict":"approve"}', $text);
    }

    public function testACompleteStreamWithoutDoneYieldsItsText(): void
    {
        $text = $this->textOf([$this->opening(), $this->content(), $this->finish(), $this->usage()]);

        self::assertSame('{"verdict":"approve"}', $text);
    }

    public function testACompleteStreamThatRepeatsItsFinishReasonOnTheUsageChunkYieldsItsText(): void
    {
        $text = $this->textOf([$this->opening(), $this->content(), $this->finish(), $this->usage(finishReason: 'stop'), self::DONE]);

        self::assertSame('{"verdict":"approve"}', $text);
    }

    public function testACompleteStreamWithKeepAliveCommentsAndCrlfLineEndsYieldsItsText(): void
    {
        $body = ": keep-alive\n\n".$this->opening().$this->content().$this->finish().$this->usage().self::DONE;

        $text = $this->textOf([str_replace("\n", "\r\n", $body)]);

        self::assertSame('{"verdict":"approve"}', $text);
    }

    public function testAnAnswerOfReasoningOnlyEndsCompleteWithoutText(): void
    {
        $text = $this->textOf([$this->opening(), $this->reasoning(), $this->finish(), $this->usage(), self::DONE]);

        self::assertSame('', $text);
    }

    public function testAStreamWhoseBodyEndsBeforeItsFinishChunkRaisesIncompleteStreamException(): void
    {
        $this->expectException(IncompleteStreamException::class);

        $this->textOf([$this->opening(), $this->content()]);
    }

    public function testAStreamThatSendsItsUsageAndDoneWithoutAFinishReasonRaisesIncompleteStreamException(): void
    {
        $this->expectException(IncompleteStreamException::class);

        $this->textOf([$this->opening(), $this->content(), $this->usage(), self::DONE]);
    }

    // Not checked against the router: if a provider sends its error in this shape, the bridge reports a cut stream instead of the error.
    public function testAnInStreamErrorObjectWithoutAnErrorKeyRaisesIncompleteStreamException(): void
    {
        $this->expectException(IncompleteStreamException::class);

        $error = 'data: '.json_encode(['object' => 'error', 'message' => 'Upstream provider failed.', 'type' => 'server_error', 'code' => 500], JSON_THROW_ON_ERROR)."\n\n";

        $this->textOf([$this->opening(), $this->content(), $error, self::DONE]);
    }

    public function testAConnectionLostMidBodyRaisesATransportErrorInsteadOfIncompleteStreamException(): void
    {
        $this->expectException(TransportExceptionInterface::class);

        $this->textOf([$this->opening(), $this->content(), new RuntimeException('Connection reset by peer.')]);
    }

    /**
     * @param list<string|Throwable> $parts
     */
    private function textOf(array $parts): string
    {
        $client = new MockHttpClient(new MockResponse($this->body($parts), ['response_headers' => ['content-type' => 'text/event-stream']]));
        $platform = Factory::createPlatform('http://localhost', 'test', $client);
        $text = '';

        foreach ($platform->invoke(self::MODEL, new MessageBag(Message::ofUser('Review the change.')), ['stream' => true])->asStream() as $delta) {
            $text .= $delta instanceof TextDelta ? $delta->getText() : '';
        }

        return $text;
    }

    /**
     * @param list<string|Throwable> $parts
     *
     * @return Generator<string>
     */
    private function body(array $parts): Generator
    {
        foreach ($parts as $part) {
            if ($part instanceof Throwable) {
                throw $part;
            }

            yield $part;
        }
    }

    private function opening(): string
    {
        return $this->chunk([['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]]);
    }

    private function reasoning(): string
    {
        return $this->chunk([['index' => 0, 'delta' => ['reasoning_content' => 'The change looks safe.'], 'finish_reason' => null]]);
    }

    private function content(): string
    {
        return $this->chunk([['index' => 0, 'delta' => ['content' => '{"verdict":"approve"}'], 'finish_reason' => null]]);
    }

    private function finish(): string
    {
        return $this->chunk([['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]);
    }

    private function usage(?string $finishReason = null): string
    {
        $choices = null === $finishReason ? [] : [['index' => 0, 'delta' => [], 'finish_reason' => $finishReason]];

        return $this->chunk($choices, ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150]);
    }

    /**
     * @param list<array<string, mixed>> $choices
     * @param array<string, int>|null    $usage
     */
    private function chunk(array $choices, ?array $usage = null): string
    {
        $data = ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'openai/gpt-oss-120b', 'choices' => $choices];

        if (null !== $usage) {
            $data['usage'] = $usage;
        }

        return 'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
    }
}
