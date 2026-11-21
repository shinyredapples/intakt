<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Doctrine\ORM\Mapping as ORM;

// Kept once a project was synced, also without a validator, because its generation is what an overlapping write is compared against.
#[ORM\Entity]
#[ORM\Table(name: 'work_source_validator')]
final class IntakeValidator
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $validator,
        #[ORM\Column(options: ['default' => 1])]
        private int $generation = 1,
    ) {
    }

    public function project(): string
    {
        return $this->project;
    }

    public function state(): SyncState
    {
        return new SyncState($this->validator, $this->generation);
    }

    public function advance(?string $validator): void
    {
        $this->validator = $validator;
        ++$this->generation;
    }
}
