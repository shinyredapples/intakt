<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Doctrine\ORM\Mapping as ORM;

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
        // Advanced by every recorded listing, because a listing of several pages stores no validator and two syncs must still tell whose rows are newer.
        #[ORM\Column(options: ['default' => 0])]
        private int $generation = 0,
    ) {
    }

    public function project(): string
    {
        return $this->project;
    }

    public function state(): IntakeState
    {
        return new IntakeState($this->validator, $this->generation);
    }

    public function advance(?string $validator): void
    {
        $this->validator = $validator;
        ++$this->generation;
    }
}
