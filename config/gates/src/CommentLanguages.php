<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommentLanguages
{
    public function __construct(
        private PhpComments $php = new PhpComments(),
        private TwigComments $twig = new TwigComments(),
        private JavaScriptComments $javaScript = new JavaScriptComments(),
    ) {
    }

    public function languageOf(Workspace $workspace, string $path): ?CommentLanguage
    {
        if ($workspace->isPhpFileInScope($path)) {
            return $this->php;
        }

        if (array_any(Scope::EXCLUDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix))) {
            return null;
        }

        return match (true) {
            str_ends_with($path, '.twig') => $this->twig,
            str_ends_with($path, '.js'), str_ends_with($path, '.mjs'), str_ends_with($path, '.cjs') => $this->javaScript,
            default => null,
        };
    }
}
