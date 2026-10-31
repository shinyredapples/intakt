<?php

declare(strict_types=1);

use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\StateMarkingStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Places and transitions come from the enums, so the machines and the code that names them cannot differ.
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', ['workflows' => [
        'review_round' => [
            'type' => 'state_machine',
            'marking_store' => ['service' => StateMarkingStore::class],
            'supports' => [ReviewRound::class],
            'initial_marking' => RoundState::Collecting->value,
            'places' => array_map(static fn (RoundState $state): string => $state->value, RoundState::cases()),
            'transitions' => [
                ReviewRound::COMPLETION => ['from' => RoundState::Collecting->value, 'to' => RoundState::Complete->value],
            ],
        ],
        'reviewer' => [
            'type' => 'state_machine',
            'marking_store' => ['service' => StateMarkingStore::class],
            'supports' => [RoundReviewer::class],
            'initial_marking' => ReviewerState::Queued->value,
            'places' => array_map(static fn (ReviewerState $state): string => $state->value, ReviewerState::cases()),
            'transitions' => array_combine(
                array_map(static fn (ReviewerTransition $step): string => $step->value, ReviewerTransition::cases()),
                array_map(static fn (ReviewerTransition $step): array => ['from' => $step->fromPlace()->value, 'to' => $step->toPlace()->value], ReviewerTransition::cases()),
            ),
        ],
    ]]);
};
