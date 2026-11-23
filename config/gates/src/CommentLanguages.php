<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommentLanguages
{
    // Because the dashboard's browser tests are ES modules (`.mjs`), a JavaScript file is any of the three extensions Node reads as JavaScript.
    private const array JAVASCRIPT_EXTENSIONS = ['.js', '.mjs', '.cjs'];
    private const array SHELL_EXTENSIONS = ['.sh', '.bash'];
    private const array YAML_EXTENSIONS = ['.yml', '.yaml'];
    // Because a script such as `bin/journey-record` has no extension, its hashbang naming a POSIX-style shell, directly or through `env` and its options such as `-S`, is what makes it a shell script.
    private const string SHELL_HASHBANG = '~\A#![ \t]*+/\S*?/(?:env[ \t]++(?:-\S*+[ \t]++)*+)?(?:ba|da|k|z)?sh(?=\s|\z)~';
    private const int HASHBANG_BYTES = 256;

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

        if (!$this->isOutsideTheExcludedFolders($path)) {
            return null;
        }

        return match (true) {
            str_ends_with($path, '.twig') => $this->twig,
            $this->endsWithOneOf($path, self::JAVASCRIPT_EXTENSIONS) => $this->javaScript,
            $this->endsWithOneOf($path, self::YAML_EXTENSIONS) => $this->yaml,
            $this->endsWithOneOf($path, self::SHELL_EXTENSIONS), $this->isExtensionlessShellScript($workspace, $path) => $this->shell,
            default => null,
        };
    }

    private function isOutsideTheExcludedFolders(string $path): bool
    {
        return !array_any(Scope::EXCLUDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }

    /**
     * @param list<string> $extensions
     */
    private function endsWithOneOf(string $path, array $extensions): bool
    {
        return array_any($extensions, static fn (string $extension): bool => str_ends_with($path, $extension));
    }

    private function isExtensionlessShellScript(Workspace $workspace, string $path): bool
    {
        $file = $workspace->root.'/'.$path;

        if (str_contains(basename($path), '.') || is_link($file) || !is_file($file) || !is_readable($file)) {
            return false;
        }

        $head = file_get_contents($file, false, null, 0, self::HASHBANG_BYTES);

        return false !== $head && 1 === preg_match(self::SHELL_HASHBANG, $head);
    }
}
