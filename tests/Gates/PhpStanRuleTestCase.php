<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
use FilesystemIterator;
use PhpParser\Node;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Testing\RuleTestCase;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @template TRule of Rule<covariant Node>
 *
 * @extends RuleTestCase<TRule>
 */
abstract class PhpStanRuleTestCase extends RuleTestCase
{
    use UsesSandboxes;

    private const string GATE_CONFIGURATION = __DIR__.'/../../config/gates/phpstan.neon';

    private string $root = '';

    private ?Closure $autoloader = null;

    protected function setUp(): void
    {
        // the test container keeps every class it reflected for the whole process, and fixtures reuse class names (a deprecated Legacy in one, a plain one in the next), so each test starts from a new instance of the compiled container
        Closure::bind(static function (): void {
            self::$containers = [];
        }, null, PHPStanTestCase::class)();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->autoloader instanceof Closure) {
            spl_autoload_unregister($this->autoloader);
        }

        $this->removeSandboxes();
        parent::tearDown();
    }

    /**
     * @return TRule
     */
    abstract protected function ruleFor(string $root): Rule;

    final protected function getRule(): Rule
    {
        return $this->ruleFor($this->root);
    }

    final protected function assertRuleRejects(string $fixture, string $expectedFinding): void
    {
        self::assertStringContainsString($expectedFinding, $this->ruleFindingsOf($fixture));
    }

    final protected function assertRuleAccepts(string $fixture): void
    {
        self::assertSame('', $this->ruleFindingsOf($fixture));
    }

    final protected function ruleFindingsOf(string $fixture): string
    {
        return $this->ruleFindingsIn($this->sandboxWith($fixture));
    }

    final protected function ruleFindingsIn(Sandbox $sandbox): string
    {
        // because RuleTestCase keeps its analyser, and with it the first rule and container, for the whole test
        self::assertSame('', $this->root, 'A test analyzes one sandbox.');

        $this->root = $sandbox->root;
        $files = $this->phpFilesUnder($sandbox->root);
        $this->autoloadClassesOf($files);

        $findings = array_map(
            fn (Error $error): string => str_replace($this->root.'/', '', $error->getFile()).':'.$error->getLine().' '.$error->getIdentifier().' '.$error->getMessage(),
            $this->gatherAnalyserErrors($files),
        );

        return implode("\n", $findings);
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $root): array
    {
        $excluded = $this->pathsTheGateExcludes();
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension() && !$this->isUnderAny(substr($file->getPathname(), strlen($root) + 1), $excluded)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    // because the in-process analysis must see the files `bin/check --only=phpstan` sees, the list comes from the gate's configuration, not a copy of it
    /**
     * @return list<string>
     */
    private function pathsTheGateExcludes(): array
    {
        preg_match('~^    excludePaths:\n((?:        - .+\n)+)~m', (string) file_get_contents(self::GATE_CONFIGURATION), $block);
        preg_match_all('~^        - %currentWorkingDirectory%/(\S+)~m', $block[1] ?? '', $paths);

        return $paths[1];
    }

    /**
     * @param list<string> $folders
     */
    private function isUnderAny(string $relativePath, array $folders): bool
    {
        return array_any($folders, static fn (string $folder): bool => str_starts_with($relativePath, $folder.'/'));
    }

    // because the test container finds a class only through an autoloader, while `phpstan analyse` also scans the analyzed files
    /**
     * @param list<string> $files
     */
    private function autoloadClassesOf(array $files): void
    {
        $classes = [];

        foreach ($files as $file) {
            foreach ($this->classesDeclaredIn($file) as $class) {
                $classes[strtolower($class)] = $file;
            }
        }

        $this->autoloader = static function (string $class) use ($classes): void {
            if (isset($classes[strtolower($class)])) {
                require_once $classes[strtolower($class)];
            }
        };
        spl_autoload_register($this->autoloader);
    }

    /**
     * @return list<string>
     */
    private function classesDeclaredIn(string $file): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents($file)), static fn (PhpToken $token): bool => !$token->isIgnorable()));
        $namespace = '';
        $classes = [];

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? new PhpToken(T_WHITESPACE, '');
            $previous = $tokens[$index - 1] ?? new PhpToken(T_WHITESPACE, '');

            if ($token->is(T_NAMESPACE) && $next->is([T_NAME_QUALIFIED, T_STRING])) {
                $namespace = $next->text.'\\';
            }

            // because `Name::class` is no declaration
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $next->is(T_STRING) && !$previous->is(T_DOUBLE_COLON)) {
                $classes[] = $namespace.$next->text;
            }
        }

        return $classes;
    }
}
