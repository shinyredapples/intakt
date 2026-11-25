<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class ForgeBranch
{
    // Named by these, because neither forge marks a release branch otherwise and cleanup must never pick one.
    private const string RELEASE_NAME = '/^releases?(\z|[\/-])/';

    public function __construct(
        public string $name,
        public CommitId $head,
        public bool $isDefault,
        public bool $isProtected,
        public bool $isSymbolic,
    ) {
    }

    public static function isReleaseName(string $name): bool
    {
        return 1 === preg_match(self::RELEASE_NAME, $name);
    }

    public function isRelease(): bool
    {
        return self::isReleaseName($this->name);
    }

    // A symbolic branch is never deletable, because deleting through it would delete the branch it names.
    public function isDeletable(): bool
    {
        return !$this->isDefault && !$this->isProtected && !$this->isSymbolic && !$this->isRelease();
    }
}
