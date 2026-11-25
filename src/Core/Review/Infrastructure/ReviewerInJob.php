<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\AccountLogin;
use Intakt\Core\Ai\Contract\AccountLogins;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Review\Contract\JobReview;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\JobVerdict;
use Intakt\Core\Review\Contract\ReviewJobRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Override;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// The job's answer is read and checked here like an answer in the app's own process, because the job's CLI, which reads a read-only clone of the exact head and reaches only its vendor, is model output like any other.
final readonly class ReviewerInJob implements JobReviewer
{
    private VerdictInText $text;

    public function __construct(
        private ReviewerPrompt $prompt,
        private JobPrompt $jobPrompt,
        private HeadClones $clones,
        private ReviewJob $jobs,
        private AccountLogins $logins,
        private VerdictParser $parser,
        private ValidatorInterface $validator,
        SerializerInterface $serializer,
        private OpenFindingIds $ids = new OpenFindingIds(),
    ) {
        // The form the job prompt asks for, because the job's CLI cannot hold its answer to a schema.
        $this->text = new VerdictInText($serializer, AnswerForm::ProseAroundBlock);
    }

    /**
     * @throws ReviewJobFailure
     */
    #[Override]
    public function review(ReviewRequest $request, JobReview $job, ResolvedModel $model): JobVerdict
    {
        // The only provider whose CLI can read files in a job; any other is refused by the job itself.
        $login = Provider::ClaudeCode === $model->provider ? $this->logins->claudeCode() : null;
        $clone = $this->clones->cloneAt($request->checkout->root(), $job->head);

        // The clone is removed after the job, also when it throws, so no head is left on the host.
        try {
            $answer = $this->answerOf($request, $job, $model, $clone, $login);
            $read = $this->clones->holdsARegularFileOf($clone, $job->head, $answer->readFiles);
        } finally {
            $this->clones->remove($clone);
        }

        return new JobVerdict($this->verdictOrRefusal($request, $answer), $read, $answer->usage, $login?->account);
    }

    private function answerOf(ReviewRequest $request, JobReview $job, ResolvedModel $model, string $clone, ?AccountLogin $login): ReviewJobAnswer
    {
        $prompt = $this->jobPrompt->textOf($this->prompt->messagesFor($request->conversation, $request->brief));

        return $this->jobs->run(new ReviewJobRequest($request->brief->change->project, $clone, $job->head->hash, $job->reviewer, $model, $prompt, $login));
    }

    private function verdictOrRefusal(ReviewRequest $request, ReviewJobAnswer $answer): Verdict|VerdictUnreadable
    {
        try {
            return $this->verdictOf($request, $answer);
        } catch (VerdictUnreadable $unreadable) {
            return $unreadable;
        }
    }

    /**
     * @throws VerdictUnreadable
     */
    private function verdictOf(ReviewRequest $request, ReviewJobAnswer $answer): Verdict
    {
        $verdict = $this->parser->parseWith(fn (): Verdict => $this->validated($this->text->verdictOf($answer->text))->toVerdict());

        $this->ids->refuseClaimedFailureIds($verdict);
        $counted = $this->ids->asTheRoundCounts($request->brief, $verdict);

        // Refused in plain code, as in the app's own process, because the note that a text was cut is only prompt text.
        if ($counted->mayCountAsApproval() && $this->prompt->cutsInputOf($request->brief)) {
            return Verdict::cutApproval();
        }

        return $this->ids->checkedAgainst($request->brief, $counted);
    }

    // Checked here, because no platform's validator sees an answer read from a job.
    private function validated(ReviewerAnswer $verdict): ReviewerAnswer
    {
        $violations = $this->validator->validate($verdict);

        return 0 === count($violations) ? $verdict : throw new ValidationException($violations);
    }
}
