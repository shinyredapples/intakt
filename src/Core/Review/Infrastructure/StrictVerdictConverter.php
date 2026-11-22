<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use TypeError;

final readonly class StrictVerdictConverter implements ResultConverterInterface
{
    // The schema sent to the model requires every field, forbids others and tells objects from lists, so the mapping holds the answer to the same rules.
    private const array STRICT = [
        AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
        AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true,
        JsonDecode::ASSOCIATIVE => false,
    ];

    public function __construct(
        private ResultConverterInterface $inner,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private AnswerForm $form = AnswerForm::Json,
    ) {
    }

    public function supports(Model $model): bool
    {
        return $this->inner->supports($model);
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $blocks = new AnthropicTextBlocks($result);
        $text = $this->inner->convert($blocks, $options);
        // A tool call is a step towards the verdict, so the agent loop gets it as it is; the final answer is the one that must be a verdict.
        if ($text instanceof ToolCallResult) {
            return $text;
        }

        // Passed on with listeners, because the agent reports each streamed delta as progress and the verdict exists only once the stream ends; the usage one goes first so a refused verdict still settles the usage.
        if ($text instanceof StreamResult) {
            $tally = new StreamTally();
            $answer = new StreamedAnswer($blocks, $tally);
            $stream = new StreamResult($answer->of($text));
            $stream->addListener(new FinalStreamedUsage());
            $stream->addListener($answer);
            $stream->addListener(new StrictVerdictStreamListener($this->verdictFrom(...), $tally));

            return $stream;
        }

        // Before the text check, because a provider that thinks before it answers sends the answer beside its thinking.
        if ($text instanceof MultiPartResult) {
            return $this->convertedMultiPart($text, $result);
        }

        if (!$text instanceof TextResult) {
            throw new UnexpectedResultTypeException(TextResult::class, $text::class);
        }

        $verdict = new ObjectResult($this->verdictFrom($text->getContent()), $text->getContent());
        $verdict->setRawResult($result);
        $verdict->getMetadata()->set($text->getMetadata()->all());

        return $verdict;
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return $this->inner->getTokenUsageExtractor();
    }

    private function verdictFrom(string $content): ReviewerAnswer
    {
        try {
            return $this->deserialized(AnswerForm::ProseAroundBlock === $this->form ? $this->fencedBlockOf($content) : $content);
        } catch (ExceptionInterface $exception) {
            // Named after the contract Verdict, the serializer's detail too, because both reach VerdictUnreadable and the reviewers' failure texts must not change.
            $detail = new UnexpectedValueException(strtr($exception->getMessage(), [ReviewerAnswer::class => Verdict::class]), $exception->getCode(), $exception);

            throw new RuntimeException(sprintf('Cannot deserialize the content into the "%s" class.', Verdict::class), $exception->getCode(), previous: $detail);
        }
    }

    // Kept as parts, because the thinking blocks and their signatures must go back to the provider with the next request.
    private function convertedMultiPart(MultiPartResult $parts, RawResultInterface $result): ResultInterface
    {
        if ($parts->asToolCallResult() instanceof ToolCallResult) {
            return $this->withoutNarration($parts, $result);
        }

        $texts = array_values(array_filter($parts->getContent(), static fn (ResultInterface $part): bool => $part instanceof TextResult));

        // Refused when it is split, because which text is the verdict would be a guess.
        if (1 !== count($texts)) {
            throw new UnexpectedResultTypeException(TextResult::class, MultiPartResult::class);
        }

        $answer = $texts[0]->getContent();

        $verdict = new ObjectResult($this->validated($this->verdictFrom($answer)), $answer);
        $converted = array_map(static fn (ResultInterface $part): ResultInterface => $part instanceof TextResult ? $verdict : $part, $parts->getContent());

        $rebuilt = new MultiPartResult($converted);
        $rebuilt->setRawResult($result);
        $rebuilt->getMetadata()->set($parts->getMetadata()->all());

        return $rebuilt;
    }

    // Dropped here because Symfony AI's structured output would read the narration before a tool call as the answer and fail on it.
    private function withoutNarration(MultiPartResult $parts, RawResultInterface $result): MultiPartResult
    {
        /** @var non-empty-list<ResultInterface> $kept */
        $kept = array_values(array_filter($parts->getContent(), static fn (ResultInterface $part): bool => !$part instanceof TextResult));
        $rebuilt = new MultiPartResult($kept);
        $rebuilt->setRawResult($result);
        $rebuilt->getMetadata()->set($parts->getMetadata()->all());

        return $rebuilt;
    }

    // Checked here because Symfony AI's Validator subscriber checks only an object result, never one among parts.
    private function validated(ReviewerAnswer $verdict): ReviewerAnswer
    {
        $violations = $this->validator->validate($verdict);

        return 0 === count($violations) ? $verdict : throw new ValidationException($violations);
    }

    // The subscription CLIs cannot hold the answer to a schema, so the model writes prose and one fenced block. The rule is deliberately simpler than Markdown and fails closed: anything but one plain top-level block leaves the text as it is, which then fails as invalid, because refusing a verdict costs a review while reading the wrong one of two costs the change.
    private function fencedBlockOf(string $content): string
    {
        // Counted anywhere, whatever comes before it on the line, so a second block in any Markdown form, or a mention in the prose, makes the answer ambiguous.
        if (1 !== preg_match_all('/(?:`{3,}|~{3,})[ \t]*json\b/i', $content)) {
            return $content;
        }

        if (1 !== preg_match('/^```json[ \t]*\r?\n(.*?)\r?\n```[ \t]*(?=\r?\n|\z)/ms', $content, $block, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        return $this->isNested(substr($content, 0, $block[0][1])) ? $content : $block[1][0];
    }

    // Refused because a block inside another fence is an example in the prose, not the answer; the odd count is the maintainer's rule, the open fence also catches a fence line with an info string, which never closes a block.
    private function isNested(string $before): bool
    {
        return $this->aFenceIsLeftOpenIn($before) || 1 === (int) preg_match_all('/^.*(?:`{3}|~{3}).*$/m', $before) % 2;
    }

    private function aFenceIsLeftOpenIn(string $text): bool
    {
        preg_match_all('/^([ \t>*+\-\d.)]*)(`{3,}|~{3,})[ \t]*(.*?)[ \t]*\r?$/m', $text, $lines, PREG_SET_ORDER);
        $open = null;
        foreach ($lines as [, $prefix, $run, $info]) {
            $line = FenceLine::of($prefix, $run, $info);
            $open = match (true) {
                !$open instanceof FenceLine => $line,
                $line->closes($open) => null,
                default => $open,
            };
        }

        return $open instanceof FenceLine;
    }

    /**
     * @throws ExceptionInterface
     */
    private function deserialized(string $content): ReviewerAnswer
    {
        try {
            return $this->serializer->deserialize($content, ReviewerAnswer::class, JsonEncoder::FORMAT, self::STRICT);
        } catch (TypeError $error) {
            // Kept so a constructor's type error still fails the mapping, because serializers before 8.2.0-BETA2 passed an object on as a list of findings: upstream/serializer-object-collection-accepts-any-object
            throw new UnexpectedValueException($error->getMessage(), $error->getCode(), $error);
        }
    }
}
