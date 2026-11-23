<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Contract\ReviewerRoles;
use Intakt\Core\Review\Contract\ReviewServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredReviewerRoles implements ReviewerRoles
{
    /**
     * @param array<string, array{reviewers: list<array{role: non-empty-string, provider: string}>, rules?: list<array{reviewers: list<array{role: non-empty-string, provider: string}>}>}> $panels
     */
    public function __construct(
        #[Autowire(param: ReviewServices::PANELS)]
        private array $panels,
    ) {
    }

    public function servedBy(Provider $provider): array
    {
        $roles = [];

        foreach ($this->everyReviewer() as $reviewer) {
            if ($reviewer['provider'] === $provider->value) {
                $roles[$reviewer['role']] = true;
            }
        }

        return array_map(strval(...), array_keys($roles));
    }

    /**
     * @return list<array{role: non-empty-string, provider: string}>
     */
    private function everyReviewer(): array
    {
        $reviewers = [];

        foreach ($this->panels as $panel) {
            $reviewers = [...$reviewers, ...$panel['reviewers'], ...array_merge(...array_column($panel['rules'] ?? [], 'reviewers'))];
        }

        return $reviewers;
    }
}
