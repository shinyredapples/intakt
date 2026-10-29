<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Verdict;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
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
    ) {
    }

    public function supports(Model $model): bool
    {
        return $this->inner->supports($model);
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $text = $this->inner->convert($result, $options);
        // A tool call is a step towards the verdict, so the agent loop gets it as it is; the final answer is the one that must be a verdict.
        if ($text instanceof ToolCallResult) {
            return $text;
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
        try {
            return $this->deserialized($content);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('Cannot deserialize the content into the "%s" class.', Verdict::class), $exception->getCode(), previous: $exception);
        }
    }

    /**
     * @throws ExceptionInterface
     */
    private function deserialized(string $content): Verdict
    {
        try {
            /** @var Verdict $verdict */
            $verdict = $this->serializer->deserialize($content, Verdict::class, 'json', self::STRICT);

            return $verdict;
        } catch (TypeError $error) {
            // The serializer passes an object on as a list of findings: upstream/serializer-object-collection-accepts-any-object
            throw new UnexpectedValueException($error->getMessage(), $error->getCode(), $error);
        }
    }
}
