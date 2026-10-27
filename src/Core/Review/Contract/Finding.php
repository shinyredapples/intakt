<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Finding
{
    public Place $place;

    public ?string $requirement;

    public ?Evidence $evidence;

    public function __construct(
        public string $id,
        public string $text,
        public Triage $triage,
    ) {
        $this->place = new Place();
        $this->requirement = null;
        $this->evidence = null;
    }

    public function at(Place $place): self
    {
        return clone ($this, ['place' => $place]);
    }

    public function citing(string $requirement): self
    {
        return clone ($this, ['requirement' => $requirement]);
    }

    public function provenBy(Evidence $evidence): self
    {
        return clone ($this, ['evidence' => $evidence]);
    }
}
