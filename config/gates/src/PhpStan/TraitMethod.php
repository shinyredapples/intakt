<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node\Stmt\ClassMethod;

final readonly class TraitMethod
{
    // the source names the declaration as Trait::method and renamedAt the alias that gave the method its name as Owner:line, because the same declaration may reach a class under several names; before keeps the identity the method had under its old name, so the original name is judged only while the class still exposes that method under it
    /**
     * @param list<string>|null $before
     */
    public function __construct(
        public string $name,
        private int $visibility,
        private string $source,
        public ?string $renamedAt = null,
        public ?array $before = null,
    ) {
    }

    public static function declaredIn(string $trait, ClassMethod $method): self
    {
        $visibility = $method->flags & Modifiers::VISIBILITY_MASK;

        return new self($method->name->toString(), 0 === $visibility ? Modifiers::PUBLIC : $visibility, $trait.'::'.$method->name->toString());
    }

    public function isPublic(): bool
    {
        return Modifiers::PUBLIC === $this->visibility;
    }

    // a visibility of 0 keeps the method's, as an adaptation without a visibility modifier does
    public function renamed(string $name, int $visibility, string $at): self
    {
        return new self($name, 0 === $visibility ? $this->visibility : $visibility, $this->source, $at, $this->identity());
    }

    public function withVisibility(int $visibility): self
    {
        return new self($this->name, 0 === $visibility ? $this->visibility : $visibility, $this->source, $this->renamedAt, $this->before);
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
