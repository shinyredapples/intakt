<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommentLanguages
{
    public function __construct(
        private PhpComments $php = new PhpComments(),
        private TwigComments $twig = new TwigComments(),
    ) {
    }

    public function languageOf(Workspace $workspace, string $path): ?CommentLanguage
    {
        if ($workspace->isPhpFileInScope($path)) {
            return $this->php;
        }

        return $this->isTwigTemplateInScope($path) ? $this->twig : null;
    }

    private function isTwigTemplateInScope(string $path): bool
    {
        return str_ends_with($path, '.twig') && !array_any(Scope::EXCLUDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
