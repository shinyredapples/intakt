<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommentLanguages
{
    // Because the dashboard's browser tests are ES modules (`.mjs`), a JavaScript file is any of the three extensions Node reads as JavaScript.
    private const array JAVASCRIPT_EXTENSIONS = ['.js', '.mjs', '.cjs'];

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

        if ($this->isTwigTemplateInScope($path)) {
            return $this->twig;
        }

        return $this->isJavaScriptFileInScope($path) ? $this->javaScript : null;
    }

    private function isTwigTemplateInScope(string $path): bool
    {
        return str_ends_with($path, '.twig') && $this->isOutsideTheExcludedFolders($path);
    }

    private function isJavaScriptFileInScope(string $path): bool
    {
        return array_any(self::JAVASCRIPT_EXTENSIONS, static fn (string $extension): bool => str_ends_with($path, $extension)) && $this->isOutsideTheExcludedFolders($path);
    }

    private function isOutsideTheExcludedFolders(string $path): bool
    {
        return !array_any(Scope::EXCLUDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
