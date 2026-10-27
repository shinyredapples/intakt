<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

final class NameStatusParser
{
    /**
     * @return list<ChangedFile>
     */
    public function parse(string $nameStatus): array
    {
        return $nameStatus
            |> $this->fields(...)
            |> $this->pairs(...)
            |> $this->toChangedFiles(...);
    }

    /**
     * @return list<string>
     */
    private function fields(string $nameStatus): array
    {
        // git ends every field with a NUL, so the last split piece is empty
        $fields = explode("\0", $nameStatus);
        array_pop($fields);

        return $fields;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<list<string>>
     */
    private function pairs(array $fields): array
    {
        return array_chunk($fields, 2);
    }

    /**
     * @param list<list<string>> $pairs
     *
     * @return list<ChangedFile>
     */
    private function toChangedFiles(array $pairs): array
    {
        return array_map($this->toChangedFile(...), $pairs);
    }

    /**
     * @param list<string> $pair
     */
    private function toChangedFile(array $pair): ChangedFile
    {
        if (2 !== count($pair)) {
            throw new ForgeReadFailed('git listed a status without a path.');
        }

        return new ChangedFile($pair[1], $this->statusOf($pair[0]));
    }

    private function statusOf(string $letter): FileStatus
    {
        return match ($letter) {
            'A' => FileStatus::Added,
            'M', 'T' => FileStatus::Modified,
            'D' => FileStatus::Deleted,
            default => throw new ForgeReadFailed(sprintf('git listed an unknown status "%s".', $letter)),
        };
    }
}
