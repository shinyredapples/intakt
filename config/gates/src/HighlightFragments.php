<?php

declare(strict_types=1);

namespace Intakt\Gates;

// One fragment per change and no committed page, so two pull requests never touch the same file.
final readonly class HighlightFragments
{
    public const string ASSEMBLED = 'docs/highlights.md';

    public const string DIRECTORY = 'docs/highlights.d';

    private const string FRAGMENT_NAME = '/^\d+-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/';

    public function __construct(
        private HighlightHistory $history = new HighlightHistory(),
    ) {
    }

    public function areOn(string $root): bool
    {
        return is_dir($root.'/'.self::DIRECTORY);
    }

    // The gates read the page as bin/highlights would write it, so their rules never judge a stale or hand-edited local copy.
    public function pageOn(string $root): ?HighlightAssembly
    {
        if ($this->areOn($root)) {
            return $this->assembled($root);
        }

        $file = $root.'/'.self::ASSEMBLED;

        if (!is_file($file)) {
            return null;
        }

        $contents = $this->contentsOf($file);

        return null === $contents ? new HighlightAssembly('', ['cannot read '.self::ASSEMBLED]) : new HighlightAssembly($contents, []);
    }

    /**
     * @return list<string>
     */
    public function committedPageFindings(string $root, ?ProcessResult $tracked): array
    {
        // Only a tracked page reaches other changes, because a local copy is ignored; without fragments the page is hand-written by design.
        if (!$tracked instanceof ProcessResult || !$this->areOn($root) || !in_array(self::ASSEMBLED, explode("\0", $tracked->output), true)) {
            return [];
        }

        return [self::ASSEMBLED.' is committed, but bin/highlights generates it from '.self::DIRECTORY.'/ and git ignores it: remove it from the index (git rm --cached '.self::ASSEMBLED.') and put the change in a fragment'];
    }

    /**
     * @return list<string>
     */
    public function localPageFindings(string $root, HighlightAssembly $page): array
    {
        $file = $root.'/'.self::ASSEMBLED;

        // A broken assembly already fails with its own findings, so only a page next to a sound one is compared.
        if (!$this->areOn($root) || [] !== $page->findings || !is_file($file) || $this->contentsOf($file) === $page->text) {
            return [];
        }

        return [self::ASSEMBLED.' is not the assembly of '.self::DIRECTORY.'/: put a change in a fragment, never in the page, and run bin/highlights'];
    }

    public function assembled(string $root): HighlightAssembly
    {
        $layout = $this->linesOf($root.'/'.HighlightLayout::FILE);

        if (null === $layout) {
            return new HighlightAssembly('', ['cannot read '.HighlightLayout::FILE]);
        }

        $order = $this->history->orderOn($root);

        if (is_string($order)) {
            return new HighlightAssembly('', [$order]);
        }

        $layout = HighlightLayout::fromLines($layout);
        $names = $this->fragmentNames($root);
        $findings = $this->nameFindings($names);
        $rowsByFragment = [];

        foreach ($this->fragmentsInOrder($names, $order) as $name) {
            [$rowsByFragment[$name], $fragmentFindings] = $this->rowsOf($root, $name, $layout);
            $findings = [...$findings, ...$fragmentFindings];
        }

        $findings = [...$findings, ...$this->unorderedRowFindings($rowsByFragment, $order)];
        $rows = array_reduce($rowsByFragment, $this->withReplacedRows(...), []);

        return new HighlightAssembly([] === $findings ? $layout->assembledWith($rows) : '', $findings);
    }

    /**
     * @return list<string>|null
     */
    private function linesOf(string $file): ?array
    {
        $contents = $this->contentsOf($file);

        return null === $contents ? null : array_map(rtrim(...), explode("\n", $contents));
    }

    /**
     * @return list<string>
     */
    private function fragmentNames(string $root): array
    {
        $entries = scandir($root.'/'.self::DIRECTORY);

        if (false === $entries) {
            return [];
        }

        return array_values(array_diff($entries, ['.', '..', basename(HighlightLayout::FILE)]));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function nameFindings(array $names): array
    {
        $misnamed = array_filter($names, static fn (string $name): bool => 1 !== preg_match(self::FRAGMENT_NAME, $name));

        return array_values(array_map(
            static fn (string $name): string => self::DIRECTORY.'/'.$name.': a fragment is named <issue>-<slug>.md, the slug in lowercase letters, digits and hyphens',
            $misnamed,
        ));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function fragmentsInOrder(array $names, HighlightOrder $order): array
    {
        $fragments = array_values(array_filter($names, static fn (string $name): bool => 1 === preg_match(self::FRAGMENT_NAME, $name)));

        // A later change replaces an earlier one's row whatever their issue numbers, so fragments apply in the order their first-parent commits reached the branch; an uncommitted fragment is the newest.
        usort($fragments, $order->compare(...));

        return $fragments;
    }

    /**
     * @return array{array<string, array<string, string>>, list<string>}
     */
    private function rowsOf(string $root, string $name, HighlightLayout $layout): array
    {
        $file = self::DIRECTORY.'/'.$name;
        $lines = $this->linesOf($root.'/'.$file);

        return null === $lines ? [[], ['cannot read '.$file]] : new HighlightFragment($file, $layout)->rowsOf($lines);
    }

    /**
     * @param array<string, array<string, array<string, string>>> $rowsByFragment
     *
     * @return list<string>
     */
    private function unorderedRowFindings(array $rowsByFragment, HighlightOrder $order): array
    {
        // Fragments that share a place would otherwise fall back to an order history does not give, and an older change could silently replace a newer row.
        $setBy = [];

        foreach ($rowsByFragment as $name => $rows) {
            foreach ($this->rowKeysOf($rows) as [$section, $key]) {
                $latest = $setBy[$section][$key] ?? [];

                // A fragment at a later place sets the row's final value, so a tie before it does not matter.
                $setBy[$section][$key] = [] !== $latest && !$order->knowsWhichCameFirst($latest[0], $name) ? [...$latest, $name] : [$name];
            }
        }

        $findings = [];

        foreach ($setBy as $section => $keys) {
            foreach ($keys as $key => $names) {
                if (count($names) > 1) {
                    $findings[] = $this->listOf($names).' set row "'.$key.'" of section "'.$section.'" at one place in the first-parent history (one commit or merge added them, they are uncommitted, or there is no history), so none of them is known to be newer: delete the superseded fragment';
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array<string, string>> $rows
     *
     * @return list<array{string, string}>
     */
    private function rowKeysOf(array $rows): array
    {
        $keys = [];

        foreach ($rows as $section => $sectionRows) {
            foreach (array_keys($sectionRows) as $key) {
                $keys[] = [$section, $key];
            }
        }

        return $keys;
    }

    /**
     * @param non-empty-list<string> $names
     */
    private function listOf(array $names): string
    {
        $files = array_map(static fn (string $name): string => self::DIRECTORY.'/'.$name, $names);
        $last = array_pop($files);

        return [] === $files ? $last : implode(', ', $files).' and '.$last;
    }

    /**
     * @param array<string, array<string, string>> $rows
     * @param array<string, array<string, string>> $replacements
     *
     * @return array<string, array<string, string>>
     */
    private function withReplacedRows(array $rows, array $replacements): array
    {
        foreach ($replacements as $section => $sectionRows) {
            foreach ($sectionRows as $key => $row) {
                $rows[$section][$key] = $row;
            }
        }

        return $rows;
    }

    private function contentsOf(string $file): ?string
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        return false === $contents ? null : $contents;
    }
}
