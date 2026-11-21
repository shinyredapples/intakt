<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Hashes rather than the texts, so a recorded identity has a fixed shape and holds no task text or command line.
final readonly class ReviewTerms
{
    private const string HASH = 'sha256';

    public function __construct(
        public string $taskRevision,
        public string $checkCommand,
        public string $panelPolicy,
    ) {
        foreach ([$taskRevision, $checkCommand, $panelPolicy] as $hash) {
            if (1 !== preg_match('/^[0-9a-f]{64}\z/', $hash)) {
                throw new ReviewTermMalformed('A review term is a lowercase hexadecimal SHA-256 hash.');
            }
        }
    }

    public static function of(string $task, string $checkCommand, Panel $panel): self
    {
        return new self(hash(self::HASH, $task), hash(self::HASH, $checkCommand), hash(self::HASH, self::policyOf($panel)));
    }

    public function describes(string $task, Panel $panel): bool
    {
        return hash(self::HASH, $task) === $this->taskRevision && hash(self::HASH, self::policyOf($panel)) === $this->panelPolicy;
    }

    private static function policyOf(Panel $panel): string
    {
        $reviewers = array_map(
            static fn (PanelReviewer $reviewer): array => [$reviewer->role->name, $reviewer->role->prompt, $reviewer->model->tier->value, $reviewer->model->provider->value, $reviewer->budget],
            $panel->reviewers,
        );

        return json_encode([$panel->quorum->value, $reviewers], JSON_THROW_ON_ERROR);
    }
}
