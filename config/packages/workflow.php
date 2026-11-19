<?php

declare(strict_types=1);

use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\StateMarkingStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Places and transitions come from the enums, so the machine and the code that names them cannot differ; the review_round machine is declared by the attributes of ReviewRoundStateMachine.
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', ['workflows' => [
        'reviewer' => [
            'type' => 'state_machine',
            'marking_store' => ['service' => StateMarkingStore::class],
            'supports' => [RoundReviewer::class],
            'initial_marking' => ReviewerState::Queued->value,
            'places' => array_map(static fn (ReviewerState $state): string => $state->value, ReviewerState::cases()),
            'transitions' => array_combine(
                array_map(static fn (ReviewerTransition $step): string => $step->value, ReviewerTransition::cases()),
                array_map(static fn (ReviewerTransition $step): array => ['from' => array_map(static fn (ReviewerState $place): string => $place->value, $step->fromPlaces()), 'to' => $step->toPlace()->value], ReviewerTransition::cases()),
            ),
        ],
    ]]);
};
