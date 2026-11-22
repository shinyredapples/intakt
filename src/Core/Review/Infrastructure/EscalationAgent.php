<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\EscalationFailed;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewed;
use Intakt\Core\Review\Contract\EscalationReviewer;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Override;
use Symfony\Component\Uid\Uuid;

// No tools, because the escalation judges one finding from the diff it is given and must not reach anything else the change holds.
final readonly class EscalationAgent implements EscalationReviewer
{
    public const string UNREADABLE = 'the answer could not be read as an escalation verdict';

    public function __construct(
        private EscalationPrompt $prompt,
        private Vendor $vendor,
        private EscalationReviews $escalations,
        private Redactor $redactor,
    ) {
    }

    #[Override]
    public function review(string $project, Uuid $round, EscalationRequest $request): EscalationReviewed|EscalationFailed
    {
        try {
            $messages = $this->prompt->messagesFor($request);
        } catch (CitedCodeNotShown $notShown) {
            return new EscalationFailed($notShown->getMessage());
        }

        if (!$this->vendor->mapsAModelTo($project, EscalationReviews::TIER)) {
            return new EscalationFailed(sprintf('project "%s" maps no model to the "%s" tier, which the escalation review needs', $project, EscalationReviews::TIER->value));
        }

        $verdict = $this->escalations->escalate($project, $round, $messages);

        return $verdict instanceof EscalationVerdict ? new EscalationReviewed($this->redacted($verdict)) : new EscalationFailed(self::UNREADABLE);
    }

    // Redacted before it is kept, because the reasons are model text that may quote a held credential and they reach the ledger, the next build and a reviewer.
    private function redacted(EscalationVerdict $verdict): EscalationVerdict
    {
        return new EscalationVerdict($verdict->outcome, trim($this->redactor->redact($verdict->reasons)));
    }
}
