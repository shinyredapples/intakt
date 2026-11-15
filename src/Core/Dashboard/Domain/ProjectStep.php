<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class ProjectStep
{
    /**
     * @param list<PanelOfProject> $panels
     */
    public function __construct(
        public array $panels,
    ) {
    }

    public function state(): StepState
    {
        return [] === $this->panels ? StepState::Missing : StepState::Done;
    }

    public function sentence(): string
    {
        if ([] === $this->panels) {
            return 'No project is configured yet: add one to the project file that INTAKT_CONFIG names.';
        }

        return 1 === count($this->panels) ? '1 project is configured.' : sprintf('%d projects are configured.', count($this->panels));
    }
}
