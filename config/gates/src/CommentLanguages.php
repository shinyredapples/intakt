<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommentLanguages
{
    // Because the dashboard's browser tests are ES modules (`.mjs`), a JavaScript file is any of the three extensions Node reads as JavaScript.
    private const array JAVASCRIPT_EXTENSIONS = ['.js', '.mjs', '.cjs'];
    // Because the workflows, Compose files and Symfony configuration use both, a YAML file is either extension the YAML specification suggests.
    private const array YAML_EXTENSIONS = ['.yml', '.yaml'];

    public function __construct(
        private PhpComments $php = new PhpComments(),
        private TwigComments $twig = new TwigComments(),
        private JavaScriptComments $javaScript = new JavaScriptComments(),
        private ShellComments $shell = new ShellComments(),
        private YamlComments $yaml = new YamlComments(),
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

        if ($this->isShellScriptInScope($path)) {
            return $this->shell;
        }

        return $this->isYamlFileInScope($path) ? $this->yaml : null;
    }

    private function isTwigTemplateInScope(string $path): bool
    {
        return str_ends_with($path, '.twig') && $this->isOutsideTheExcludedFolders($path);
    }

    private function isJavaScriptFileInScope(string $path): bool
    {
        return array_any(self::JAVASCRIPT_EXTENSIONS, static fn (string $extension): bool => str_ends_with($path, $extension)) && $this->isOutsideTheExcludedFolders($path);
    }

    private function isShellScriptInScope(string $path): bool
    {
        return str_ends_with($path, '.sh') && $this->isOutsideTheExcludedFolders($path);
    }

    private function isYamlFileInScope(string $path): bool
    {
        return array_any(self::YAML_EXTENSIONS, static fn (string $extension): bool => str_ends_with($path, $extension)) && $this->isOutsideTheExcludedFolders($path);
    }

    private function isOutsideTheExcludedFolders(string $path): bool
    {
        return !array_any(Scope::EXCLUDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
