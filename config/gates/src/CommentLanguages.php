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
        private ShellComments $shell = new ShellComments(),
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

        if ($this->isJavaScriptFileInScope($path)) {
            return $this->javaScript;
        }

        return $this->isShellScriptInScope($path) ? $this->shell : null;
    }

    private function isTwigTemplateInScope(string $path): bool
    {
        return str_ends_with($path, '.twig') && $this->isOutsideTheExcludedFolders($path);
    }

    private function isJavaScriptFileInScope(string $path): bool
    {
        return array_any(self::JAVASCRIPT_EXTENSIONS, static fn (string $extension): bool => str_ends_with($path, $extension)) && $this->isOutsideTheExcludedFolders($path);
    }

    // Because a script without the extension cannot be told from other extensionless files by its path, only `.sh` files are read as shell.
    private function isShellScriptInScope(string $path): bool
    {
        return str_ends_with($path, '.sh') && $this->isOutsideTheExcludedFolders($path);
    }

    private function isOutsideTheExcludedFolders(string $path): bool
    {
        return !array_any(Scope::EXCLUDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
