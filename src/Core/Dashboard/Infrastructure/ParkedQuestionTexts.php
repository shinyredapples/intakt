<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\ParkedQuestion;
use Intakt\Core\Build\Contract\ParkedQuestions;
use Intakt\Core\Platform\Contract\Redactor;

// Redacted, because the builder wrote every text of the question and a job's output can carry a credential, as a step's failure can.
final readonly class ParkedQuestionTexts
{
    public function __construct(
        private ParkedQuestions $questions,
        private Redactor $redactor,
    ) {
    }

    /**
     * @return array{text: string, choices: list<string>, recommendation: string, parked_at: string}|null
     */
    public function of(string $project, string $task): ?array
    {
        // Only the number the build writes, so a task the ledger names in another form never reaches the database.
        $number = filter_var($task, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $question = false === $number ? null : $this->questions->of($project, $number);

        return $question instanceof ParkedQuestion ? $this->textsOf($question) : null;
    }

    /**
     * @return array{text: string, choices: list<string>, recommendation: string, parked_at: string}
     */
    private function textsOf(ParkedQuestion $question): array
    {
        return [
            'text' => $this->redactor->redact($question->question),
            'choices' => array_map($this->redactor->redact(...), $question->choices),
            'recommendation' => $this->redactor->redact($question->recommendation),
            'parked_at' => $question->parkedAt->format(DATE_ATOM),
        ];
    }
}
