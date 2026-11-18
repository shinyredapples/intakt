<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\JobReview;
use Intakt\Core\Review\Contract\ReviewJobRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// The job's answer is read and checked here like an answer in the app's own process, because the job's CLI, which reads a read-only clone of the exact head and reaches only its vendor, is model output like any other.
final readonly class ReviewerInJob
{
    private VerdictInText $text;

    public function __construct(
        private ReviewerPrompt $prompt,
        private JobPrompt $jobPrompt,
        private HeadClones $clones,
        private ReviewJob $jobs,
        private VerdictParser $parser,
        private ValidatorInterface $validator,
        SerializerInterface $serializer,
        private OpenFindingIds $ids = new OpenFindingIds(),
    ) {
        // The form the job prompt asks for, because the job's CLI cannot hold its answer to a schema.
        $this->text = new VerdictInText($serializer, AnswerForm::ProseAroundBlock);
    }

    /**
     * @throws VerdictUnreadable
     * @throws ReviewJobFailure
     */
    public function review(ReviewRequest $request, JobReview $job, ResolvedModel $model): Verdict
    {
        $answer = $this->answerOf($request, $job, $model);
        $verdict = $this->parser->parseWith(fn (): Verdict => $this->validated($this->text->verdictOf($answer->text)));

        // Refused in plain code, as in the app's own process, because the note that a text was cut is only prompt text.
        if (Decision::Approve === $verdict->verdict && $this->prompt->cutsInputOf($request->brief)) {
            return Verdict::cutApproval();
        }

        return $this->ids->checkedAgainst($request->brief, $verdict);
    }

    // The clone is removed after the job, also when it throws, so no head is left on the host.
    private function answerOf(ReviewRequest $request, JobReview $job, ResolvedModel $model): ReviewJobAnswer
    {
        $prompt = $this->jobPrompt->textOf($this->prompt->messagesFor($request->conversation, $request->brief));
        $clone = $this->clones->cloneAt($request->checkout->root(), $job->head);

        try {
            return $this->jobs->run(new ReviewJobRequest($request->brief->change->project, $clone, $job->head->hash, $job->reviewer, $model, $prompt));
        } finally {
            $this->clones->remove($clone);
        }
    }

    // Checked here, because no platform's validator sees an answer read from a job.
    private function validated(Verdict $verdict): Verdict
    {
        $violations = $this->validator->validate($verdict);

        return 0 === count($violations) ? $verdict : throw new ValidationException($violations);
    }
}
