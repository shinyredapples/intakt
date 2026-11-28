<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

final readonly class TraitMethod
{
    /**
     * @param string            $source    the trait and the name of the declaration, as Trait::method
     * @param string|null       $renamedAt the class or trait and the line of the alias that gave the method its name, as Owner:line
     * @param list<string>|null $before    the identity the method had under its name before that alias
     */
    public function __construct(
        public string $name,
        public bool $isPublic,
        private string $source,
        public ?string $renamedAt = null,
        public ?array $before = null,
    ) {
    }

    public static function declaredIn(string $trait, string $name, bool $isPublic): self
    {
        return new self($name, $isPublic, $trait.'::'.$name);
    }

    public function renamed(string $name, ?bool $isPublic, string $at): self
    {
        return new self($name, $isPublic ?? $this->isPublic, $this->source, $at, $this->identity());
    }

    public function withVisibility(?bool $isPublic): self
    {
        return new self($this->name, $isPublic ?? $this->isPublic, $this->source, $this->renamedAt, $this->before);
    }

    // class and trait names hold no colon, so the parts cannot run into each other
    /**
     * @return list<string>
     */
    public function identity(): array
    {
        return [$this->source, $this->renamedAt ?? ''];
    }
}
