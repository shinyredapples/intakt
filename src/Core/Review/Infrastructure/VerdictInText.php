<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use TypeError;

// One home for reading an answer's text as a verdict, so a platform's answer and a review job's answer are held to the same rules; built by its users, which know the answer form.
#[Exclude]
final readonly class VerdictInText
{
    // The schema sent to the model requires every field, forbids others and tells objects from lists, so the mapping holds the answer to the same rules.
    private const array STRICT = [
        AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
        AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true,
        JsonDecode::ASSOCIATIVE => false,
    ];

    public function __construct(
        private SerializerInterface $serializer,
        private AnswerForm $form,
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function verdictOf(string $content): ReviewerAnswer
    {
        try {
            return $this->deserialized(AnswerForm::ProseAroundBlock === $this->form ? $this->fencedBlockOf($content) : $content);
        } catch (ExceptionInterface $exception) {
            // Named after the contract Verdict, the serializer's detail too, because both reach VerdictUnreadable and the reviewers' failure texts must not change.
            $detail = new UnexpectedValueException(strtr($exception->getMessage(), [ReviewerAnswer::class => Verdict::class]), $exception->getCode(), $exception);

            throw new RuntimeException(sprintf('Cannot deserialize the content into the "%s" class.', Verdict::class), $exception->getCode(), previous: $detail);
        }
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
