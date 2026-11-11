<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Either a tree of the project at HEAD or a Dockerfile Intakt wrote, so a generated image builds without the project's files.
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

    // The tree relative to the repository root (empty for the root), because git archive takes it so; the Dockerfile relative to the tree, because the engine reads it so.
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

    public function summary(): string
    {
        if (!$this->php instanceof PhpProfile) {
            return sprintf('Job image from %s, without composer.json to check its PHP against.', $this->source->label());
        }

        $extensions = [] === $this->php->extensions ? '' : ' with '.implode(', ', $this->php->extensions);

        return sprintf('Job image from %s for PHP %s%s.', $this->source->label(), $this->php->rule, $extensions);
    }
}
