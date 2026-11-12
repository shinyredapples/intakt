<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Read per diff record, so the container has no reason to hold it.
#[Exclude]
final readonly class ChangedPath
{
    private const string SUBMODULE_MODE = '160000';

    // One record of "git diff-tree --raw -z --no-renames": the two modes, the two objects and a status letter without a score, since renames and copies are never detected.
    private const string RECORD = '/\A:([0-7]{6}) ([0-7]{6}) [0-9a-f]{40,64} [0-9a-f]{40,64} [ADMTUX]\z/';

    public function __construct(
        public string $path,
        public bool $submodule,
    ) {
    }

    /**
     * @return list<self>
     *
     * @throws ChangeNotRead
     */
    public static function listFrom(string $raw): array
    {
        if ('' === $raw) {
            return [];
        }

        $fields = explode("\0", $raw);

        // Every field ends with a NUL, so the last part is empty, and the rest are pairs of a record and its one path.
        if ('' !== array_pop($fields) || 0 !== count($fields) % 2) {
            throw ChangeNotRead::because('git printed a raw diff record that is not one');
        }

        return array_map(self::fromRecord(...), array_chunk($fields, 2));
    }

    /**
     * @param array<int, string> $record
     *
     * @throws ChangeNotRead
     */
    private static function fromRecord(array $record): self
    {
        [$status, $path] = $record;

        if ('' === $path || 1 !== preg_match(self::RECORD, $status, $modes)) {
            throw ChangeNotRead::because('git printed a raw diff record that is not one');
        }

        return new self($path, self::SUBMODULE_MODE === $modes[1] || self::SUBMODULE_MODE === $modes[2]);
    }
}
