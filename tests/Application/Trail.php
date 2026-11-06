<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

// Where a state walk stands: the path from the service (service id, then property names and array keys) and the objects it passed on the way there.
final readonly class Trail
{
    /**
     * @param list<int|string> $steps
     * @param array<int, true> $ancestors
     */
    private function __construct(private array $steps, private array $ancestors)
    {
    }

    public static function fromService(string $id): self
    {
        return new self([$id], []);
    }

    public function into(int|string $key): self
    {
        return new self([...$this->steps, $key], $this->ancestors);
    }

    public function through(object $object): self
    {
        // Added, not unpacked into a new array, because unpacking renumbers the integer ids.
        return new self($this->steps, $this->ancestors + [spl_object_id($object) => true]);
    }

    // Only an object still on the way down can have this id, because every frame of the walk holds its object until it returns.
    public function passed(object $object): bool
    {
        return isset($this->ancestors[spl_object_id($object)]);
    }

    public function key(): string
    {
        return json_encode($this->steps, JSON_THROW_ON_ERROR);
    }
}
