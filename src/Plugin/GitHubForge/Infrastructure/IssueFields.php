<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// One mapping for a listed issue and an issue read alone, so labels, title and body come out the same way from both reads.
final readonly class IssueFields
{
    public function __construct(private GitHubMoments $moments)
    {
    }

    /**
     * @param array<mixed> $issue
     */
    public function listedIssueOf(array $issue): ListedIssue
    {
        $number = $issue['number'] ?? null;
        $body = $issue['body'] ?? '';
        $due = $this->at($issue, 'milestone', 'due_on');
        if (!is_int($number) || $number < 1 || !is_string($body)) {
            throw new ForgeReadFailed('GitHub listed an issue this forge cannot read.');
        }

        return new ListedIssue(
            $number,
            $this->text($issue, 'title'),
            $body,
            $this->labelsOf($issue),
            $this->text($issue, 'user', 'login'),
            $this->moments->parse($this->text($issue, 'updated_at')),
            is_string($due) ? $this->moments->parse($due) : null,
            array_key_exists('pull_request', $issue),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function text(array $data, string ...$keys): string
    {
        $value = $this->at($data, ...$keys);

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
    }

    /**
     * @param array<mixed> $issue
     *
     * @return list<string>
     */
    private function labelsOf(array $issue): array
    {
        $labels = $issue['labels'] ?? [];
        if (!is_array($labels)) {
            throw new ForgeReadFailed('GitHub listed an issue with labels this forge cannot read.');
        }

        return array_values(array_map(fn (mixed $label): string => is_array($label) ? $this->text($label, 'name') : throw new ForgeReadFailed('GitHub listed a label that is not an object.'), $labels));
    }
}
