<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IgnoredErrorsReader
{
    private const array REQUIRED = ['identifier', 'path', 'count'];
    private const array MESSAGES = ['message', 'rawMessage'];

    /**
     * @param array<string, RatchetListFile> $lists
     *
     * @return array<string, IgnoredErrors>
     */
    public function read(Workspace $workspace, ScratchDirectory $scratch, array $lists): array
    {
        $commands = [];
        $folders = [];

        foreach (array_values($lists) as $index => $list) {
            $folders[$index] = $scratch->path.'/'.$index;
            $commands[$index] = [$this->dumpCommand($workspace, $this->copied($folders[$index], $list)), []];
        }

        $read = [];
        $results = $workspace->commandsConcurrently($commands);

        foreach (array_keys($lists) as $index => $name) {
            $read[$name] = $this->ignoredErrorsOf($results[$index], $this->resolved($folders[$index]).'/');
        }

        return $read;
    }

    // Because PHPStan resolves each entry's path from the list's own folder, a copy at the same place in a scratch tree yields repository paths for any version.
    private function copied(string $folder, RatchetListFile $list): string
    {
        $copy = $folder.'/'.$list->path;
        mkdir(dirname($copy), 0o700, true);
        file_put_contents($copy, $list->contents);

        // Because PHPStan refuses an entry whose path does not exist, and the scratch tree holds no code.
        $wrapper = $folder.'.neon';
        file_put_contents($wrapper, "includes:\n    - '".str_replace("'", "''", $copy)."'\nparameters:\n    reportUnmatchedIgnoredErrors: false\n");

        return $wrapper;
    }

    /**
     * @return list<string>
     */
    private function dumpCommand(Workspace $workspace, string $wrapper): array
    {
        return [$workspace->tool('phpstan'), 'dump-parameters', '--json', '--level=0', '--no-interaction', '--configuration='.$wrapper];
    }

    // Because PHPStan prints the paths it resolved, which pass through any symbolic link in the scratch directory's path.
    private function resolved(string $folder): string
    {
        $real = realpath($folder);

        return false === $real ? $folder : $real;
    }

    private function ignoredErrorsOf(ProcessResult $result, string $folder): IgnoredErrors
    {
        $dump = JsonNode::parse($result->output);

        if (!$result->succeeded() || !$dump instanceof JsonNode) {
            return new IgnoredErrors([], ['PHPStan cannot read it: '.$result->firstErrorLine()]);
        }

        $counts = [];
        $problems = [];

        foreach ($dump->get('ignoreErrors')->items() as $number => $entry) {
            $counted = $this->countedEntry($entry->entries(), $folder);

            if (null === $counted) {
                $problems[] = 'entry '.($number + 1).' needs exactly identifier, path inside the repository, count and one of message or rawMessage';
                continue;
            }

            $counts[$counted[0]] = ($counts[$counted[0]] ?? 0) + $counted[1];
        }

        return new IgnoredErrors($counts, $problems);
    }

    /**
     * @param array<string, JsonNode> $fields
     *
     * @return array{string, int}|null
     */
    private function countedEntry(array $fields, string $folder): ?array
    {
        $names = array_keys($fields);
        $message = array_values(array_intersect(self::MESSAGES, $names));
        $path = ($fields['path'] ?? null)?->text() ?? '';
        $count = ($fields['count'] ?? null)?->text() ?? '';

        if (1 !== count($message) || [] !== array_diff($names, [...self::REQUIRED, ...self::MESSAGES]) || [] !== array_diff(self::REQUIRED, $names)) {
            return null;
        }

        if (!str_starts_with($path, $folder) || 1 !== preg_match('/^[1-9]\d*$/', $count)) {
            return null;
        }

        $entry = [$fields['identifier']->text(), substr($path, strlen($folder)), $message[0], $fields[$message[0]]->text()];

        return [json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $count];
    }
}
