<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict;
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
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class StrictVerdictConverter implements ResultConverterInterface
{
    private VerdictInText $text;

    public function __construct(
        private ResultConverterInterface $inner,
        SerializerInterface $serializer,
        private ValidatorInterface $validator,
        AnswerForm $form = AnswerForm::Json,
    ) {
        $this->text = new VerdictInText($serializer, $form);
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

    private function verdictFrom(string $content): Verdict
    {
        return $this->text->verdictOf($content);
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
    private function validated(Verdict $verdict): Verdict
    {
        $violations = $this->validator->validate($verdict);

        return 0 === count($violations) ? $verdict : throw new ValidationException($violations);
    }
}
