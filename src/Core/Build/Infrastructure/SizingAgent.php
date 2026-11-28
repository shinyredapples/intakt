<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\Sizer;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
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

final readonly class SizingAgent implements Sizer
{
    public function __construct(private SizingPrompt $prompt)
    {
    }

    #[Override]
    public function size(SizingRequest $request, PlatformInterface $platform, string $model): ?SizingVerdict
    {
        $agent = new Agent($platform, $model, name: 'sizer');
        $execution = $agent->call($this->prompt->messagesFor($request), [PlatformSubscriber::RESPONSE_FORMAT => SizingVerdict::class]);

        $verdict = $this->answerIn($execution);

        return $verdict instanceof SizingVerdict && $verdict->agreesWithItself() ? $verdict : null;
    }

    // Anything the Validator or the mapping refuses is no answer; a failed call is not the sizer's answer, so it stays an error.
    private function answerIn(ExecutionInterface $execution): ?SizingVerdict
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

        return $answer instanceof SizingVerdict ? $answer : null;
    }
}
