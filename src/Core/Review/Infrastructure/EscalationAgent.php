<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewer;
use Intakt\Core\Review\Contract\EscalationVerdict;
use JsonException;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

// No tools, because the escalation judges one finding from the task and the diff it is given and must not reach anything else the change holds.
final readonly class EscalationAgent implements EscalationReviewer
{
    public function __construct(private EscalationPrompt $prompt)
    {
    }

    #[Override]
    public function review(EscalationRequest $request, PlatformInterface $platform, string $model): ?EscalationVerdict
    {
        $agent = new Agent($platform, $model, name: 'escalation');

        return $this->answerIn($agent->call($this->prompt->messagesFor($request), [PlatformSubscriber::RESPONSE_FORMAT => EscalationVerdict::class]));
    }

    // Anything the Validator or the mapping refuses is no answer; a failed call is not the reviewer's answer, so it stays an error.
    private function answerIn(ExecutionInterface $execution): ?EscalationVerdict
    {
        try {
            $answer = $execution->getContent();
        } catch (ValidationException) {
            return null;
        } catch (RuntimeException $exception) {
            $cause = $exception->getPrevious();
            if ($exception instanceof UnexpectedResultTypeException || $cause instanceof JsonException || $cause instanceof ExceptionInterface) {
                return null;
            }

            throw $exception;
        }

        return $answer instanceof EscalationVerdict ? $answer : null;
    }
}
