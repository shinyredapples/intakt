<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class StrictVerdictSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ResponseFormatFactoryInterface $responseFormats,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Ahead of Symfony AI's structured output, because it unwraps a fenced block and lets fields go missing or unknown.
        return [ResultEvent::class => ['processResult', 10]];
    }

    public function processResult(ResultEvent $event): void
    {
        if (!$this->asksForAVerdict($event->getOptions())) {
            return;
        }

        $deferred = $event->getDeferredResult();
        $converter = new StrictVerdictConverter($deferred->getResultConverter(), $this->serializer, $this->validator, $this->formOf($event->getModel()));
        $event->setDeferredResult(new DeferredResult($converter, $deferred->getRawResult(), $event->getOptions()));
    }

    private function formOf(Model $model): AnswerForm
    {
        return $model instanceof ClaudeCode || $model instanceof Codex ? AnswerForm::ProseAroundBlock : AnswerForm::Json;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function asksForAVerdict(array $options): bool
    {
        return ($options[PlatformSubscriber::RESPONSE_FORMAT] ?? null) === $this->responseFormats->create(ReviewerAnswer::class);
    }
}
