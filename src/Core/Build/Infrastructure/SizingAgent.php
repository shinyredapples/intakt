<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\Sizer;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use JsonException;
use Override;
use ReflectionMethod;
use ReflectionParameter;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
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

        return $answer instanceof SizingVerdict && $this->holdsOnlyTheVerdictsFields($execution->getResult()) ? $answer : null;
    }

    // Checked on the answer's JSON, because the mapping drops a field it does not know, and an answer with a field the schema does not ask for is a steered one.
    private function holdsOnlyTheVerdictsFields(ResultInterface $result): bool
    {
        $fields = $result instanceof ObjectResult ? json_decode($result->getText() ?? '', true) : null;

        if (!is_array($fields)) {
            return false;
        }

        $expected = array_map(static fn (ReflectionParameter $parameter): string => $parameter->name, new ReflectionMethod(SizingVerdict::class, '__construct')->getParameters());
        $answered = array_keys($fields);
        sort($expected);
        sort($answered);

        return $expected === $answered;
    }
}
