<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

// Written with one insert on the connection, because a flush on the ledger's shared manager closes it when two runs store the same task; the question stays here and never in the ledger, because the builder wrote it.
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
final readonly class ParkedTaskRecord
{
    public const string TABLE = 'build_parked_task';

    #[ORM\Column(type: 'text')]
    private string $question;

    /** @var non-empty-list<string> */
    #[ORM\Column(type: 'json')]
    private array $choices;

    #[ORM\Column(type: 'text')]
    private string $recommendation;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private string $project,
        #[ORM\Id]
        #[ORM\Column]
        private int $task,
        BuildQuestion $question,
        #[ORM\Column(name: 'parked_at', type: 'utc_datetime_immutable')]
        private DateTimeImmutable $parkedAt,
    ) {
        $this->question = $question->question;
        $this->choices = $question->choices;
        $this->recommendation = $question->recommendation;
    }

    /**
     * @return array{project: string, task: int, question: string, choices: non-empty-list<string>, recommendation: string, parked_at: DateTimeImmutable}
     */
    public function row(): array
    {
        return ['project' => $this->project, 'task' => $this->task, 'question' => $this->question, 'choices' => $this->choices, 'recommendation' => $this->recommendation, 'parked_at' => $this->parkedAt];
    }

    /**
     * @return array{task: string, choices: string, parked_at: string}
     */
    public static function rowTypes(): array
    {
        return ['task' => 'integer', 'choices' => 'json', 'parked_at' => 'utc_datetime_immutable'];
    }
}
