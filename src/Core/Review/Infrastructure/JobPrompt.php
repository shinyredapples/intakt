<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Verdict;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactoryInterface;

// The CLI in a review job reads one text on its standard input and is given no schema, so the reviewer's messages become one prompt that ends with the form of the answer.
final readonly class JobPrompt
{
    private const string SEPARATOR = "\n\n";

    private const string ANSWER_FORM = 'Answer with one fenced block that opens with a line ```json and closes with a line ```, holding the verdict as one JSON object that matches this JSON schema; prose before it is allowed, and no other block may be marked json. The schema: %s';

    public function __construct(
        private ResponseFormatFactoryInterface $formats,
    ) {
    }

    // In the messages' own order, so every fence the reviewer prompt put around untrusted text stays as it was.
    public function textOf(MessageBag $messages): string
    {
        $texts = array_map(static fn (object $message): string => match (true) {
            $message instanceof SystemMessage => (string) $message->getContent(),
            $message instanceof UserMessage => (string) $message->asText(),
            default => '',
        }, $messages->getMessages());

        return implode(self::SEPARATOR, [...array_filter($texts, static fn (string $text): bool => '' !== $text), $this->answerForm()]);
    }

    private function answerForm(): string
    {
        $format = $this->formats->create(Verdict::class);

        return sprintf(self::ANSWER_FORM, json_encode($format['json_schema']['schema'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
