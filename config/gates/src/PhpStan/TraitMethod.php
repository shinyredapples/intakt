<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node\Stmt\ClassMethod;

final readonly class TraitMethod
{
    // the source names the declaration as Trait::method and renamedAt the alias that gave the method its name as Owner:line, because the same declaration may reach a class under several names; before keeps the identity the method had under its old name, so the original name is judged only while the class still exposes that method under it; modifiers hold the visibility and whether the declaration is abstract
    /**
     * @param list<string>|null $before
     */
    public function __construct(
        public string $name,
        private int $modifiers,
        private string $source,
        public ?string $renamedAt = null,
        public ?array $before = null,
    ) {
    }

    public static function declaredIn(string $trait, ClassMethod $method): self
    {
        $visibility = $method->flags & Modifiers::VISIBILITY_MASK;

        return new self($method->name->toString(), (0 === $visibility ? Modifiers::PUBLIC : $visibility) | ($method->flags & Modifiers::ABSTRACT), $trait.'::'.$method->name->toString());
    }

    public function isPublic(): bool
    {
        return Modifiers::PUBLIC === ($this->modifiers & Modifiers::VISIBILITY_MASK);
    }

    public function isAbstract(): bool
    {
        return 0 !== ($this->modifiers & Modifiers::ABSTRACT);
    }

    // a visibility of 0 keeps the method's, as an adaptation without a visibility modifier does
    public function renamed(string $name, int $visibility, string $at): self
    {
        return new self($name, $this->modifiersWith($visibility), $this->source, $at, $this->identity());
    }

    public function withVisibility(int $visibility): self
    {
        return new self($this->name, $this->modifiersWith($visibility), $this->source, $this->renamedAt, $this->before);
    }

    // class and trait names hold no colon, so the parts cannot run into each other
    /**
     * @return list<string>
     */
    public function identity(): array
    {
        return [$this->source, $this->renamedAt ?? ''];
    }

    private function modifiersWith(int $visibility): int
    {
        return 0 === $visibility ? $this->modifiers : $visibility | ($this->modifiers & Modifiers::ABSTRACT);
    }
}
