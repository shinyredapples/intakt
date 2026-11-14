<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Domain\LostContent;

final readonly class CheckJobOutcome
{
    /**
     * @param list<LostContent> $lostContent
     */
    public function __construct(
        public CheckVerdict $verdict,
        public ?int $exitCode = null,
        public array $lostContent = [],
        public string $log = '',
    ) {
    }

    public function passed(): bool
    {
        return CheckVerdict::Passed === $this->verdict;
    }
}
