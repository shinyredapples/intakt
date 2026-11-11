<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Either a tree of the project at HEAD with a Dockerfile in it, or a Dockerfile Intakt wrote, which then builds without the project's files.
#[Exclude]
final readonly class JobImagePlan
{
    private const string DOCKERFILE = 'Dockerfile';

    private function __construct(
        public JobImageSource $source,
        public string $tree,
        public string $dockerfile,
        public ?string $generated = null,
        public ?PhpProfile $php = null,
    ) {
    }

    // The tree is relative to the repository root, empty for the root itself; the Dockerfile is relative to the tree.
    public static function fromTree(JobImageSource $source, string $tree, string $dockerfile): self
    {
        return new self($source, $tree, $dockerfile);
    }

    public static function generated(JobImageSource $source, string $dockerfile): self
    {
        return new self($source, '', self::DOCKERFILE, $dockerfile);
    }

    public function withPhp(?PhpProfile $php): self
    {
        return clone ($this, ['php' => $php]);
    }
}
