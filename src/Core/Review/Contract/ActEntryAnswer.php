<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use JsonSerializable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The MCP answers json_encode their act list, so the judge's note, private on Finding to keep the other answers' shape, is added here; fenced, because it is a model's text the reading agent must take as data: docs/design/05-architecture/14-threat-model.md#third-party-text
#[Exclude]
final readonly class ActEntryAnswer implements JsonSerializable
{
    public function __construct(private Finding $entry)
    {
    }

    /**
     * @param list<Finding> $act
     *
     * @return list<self>
     */
    public static function listOf(array $act): array
    {
        return array_map(static fn (Finding $entry): self => new self($entry), $act);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $fields = get_object_vars($this->entry);
        $note = $this->entry->judgeNote();

        return null === $note ? $fields : [...$fields, 'judge_note' => new Fence()->wrap(UntrustedSource::ActList, $note)->rendered()];
    }
}
