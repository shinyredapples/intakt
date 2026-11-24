<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use ArrayIterator;
use IteratorAggregate;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Traversable;

// The labels an issue or pull request holds, in the order GitHub answered them, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
#[Exclude]
final readonly class IssueLabels implements IteratorAggregate
{
    /** @var list<string> */
    private array $labels;

    public function __construct(string ...$labels)
    {
        $this->labels = array_values($labels);
    }

    public function contains(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->labels);
    }
}
