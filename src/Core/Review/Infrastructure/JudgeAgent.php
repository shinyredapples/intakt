<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Judge;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
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

final readonly class JudgeAgent implements Judge
{
    public function __construct(private JudgePrompt $prompt)
    {
    }

    #[Override]
    public function judge(JudgeRequest $request, PlatformInterface $platform, string $model): Judgement
    {
        $agent = new Agent($platform, $model, name: 'judge');
        $execution = $agent->call($this->prompt->messagesFor($request), [PlatformSubscriber::RESPONSE_FORMAT => Judgement::class]);

        $judgement = $this->answerIn($execution);

        return $judgement instanceof Judgement && $this->marksEachFindingOnce($request->findings, $judgement) ? $judgement : Judgement::everyBlockingFindingActs($request->findings);
    }

    // Anything the Validator or the mapping refuses is no answer; a failed call is not the judge's answer, so it stays an error.
    private function answerIn(ExecutionInterface $execution): ?Judgement
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

        return $answer instanceof Judgement ? $answer : null;
    }

    // Plain code checks the ids, because only the request knows which findings were given; an id the judge invented or left out must not decide what the builder fixes.
    /**
     * @param list<Finding> $findings
     */
    private function marksEachFindingOnce(array $findings, Judgement $judgement): bool
    {
        $given = array_map(static fn (Finding $finding): string => $finding->id, $findings);
        $marked = array_map(static fn (JudgedFinding $mark): string => $mark->id, $judgement->marks);

        sort($given);
        sort($marked);

        return $given === $marked;
    }
}
