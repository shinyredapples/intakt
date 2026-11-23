<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommentLanguages
{
    // Because the dashboard's browser tests are ES modules (`.mjs`), a JavaScript file is any of the three extensions Node reads as JavaScript.
    private const array JAVASCRIPT_EXTENSIONS = ['.js', '.mjs', '.cjs'];
    // Because the interpreter is named on the first line, directly or through `env` (also `env -S`, which passes it options), as `sh`, `bash`, `dash`, `ksh` or `zsh`.
    private const string SHELL_SHEBANG = '~\A#![ \t]*\S*/(?:env[ \t]+(?:-S[ \t]*)?)?(?:ba|da|k|z)?sh(?:[ \t\r\n]|\z)~';
    private const int SHEBANG_BYTES = 256;

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

        return $this->isShellScriptInScope($workspace, $path) ? $this->shell : null;
    }

    private function isTwigTemplateInScope(string $path): bool
    {
        return str_ends_with($path, '.twig') && $this->isOutsideTheExcludedFolders($path);
    }

    private function isJavaScriptFileInScope(string $path): bool
    {
        return array_any(self::JAVASCRIPT_EXTENSIONS, static fn (string $extension): bool => str_ends_with($path, $extension)) && $this->isOutsideTheExcludedFolders($path);
    }

    private function isShellScriptInScope(Workspace $workspace, string $path): bool
    {
        if (!$this->isOutsideTheExcludedFolders($path)) {
            return false;
        }

        return str_ends_with($path, '.sh') || (!str_contains(basename($path), '.') && $this->startsWithAShellShebang($workspace->root.'/'.$path));
    }

    // Because a script such as `bin/journey-record` has no extension, its first line is what names it as shell.
    private function startsWithAShellShebang(string $file): bool
    {
        if (is_link($file) || !is_file($file) || !is_readable($file)) {
            return false;
        }

        $head = file_get_contents($file, length: self::SHEBANG_BYTES);

        return false !== $head && 1 === preg_match(self::SHELL_SHEBANG, $head);
    }

    private function isOutsideTheExcludedFolders(string $path): bool
    {
        return !array_any(Scope::EXCLUDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
