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

    // Null too for a question another run parked, because overlapping runs share the task's one row and a reader shows one run.
    /**
     * @return array{text: string, choices: list<string>, recommendation: string, parked_at: string}|null
     */
    public function of(string $project, string $task, ?string $run): ?array
    {
        // Only the number the build writes, so a task the ledger names in another form never reaches the database.
        $number = (int) $task;
        $question = $number >= 1 && (string) $number === $task ? $this->questions->of($project, $number) : null;

        return $question instanceof ParkedQuestion && $question->run === $run ? $this->textsOf($question) : null;
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
