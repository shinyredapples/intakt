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
 * @extends RuleTestCase<Rule<Node>>
 */
abstract class PhpStanRuleTestCase extends RuleTestCase
{
    use UsesSandboxes;

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
     * @return Rule<Node>
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

    // only the rule under test runs, and its findings read as the gate prints them
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
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    // the test container finds a class only through an autoloader, while `phpstan analyse` also scans the analyzed files
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
            $next = $tokens[$index + 1] ?? null;

            if ($token->is(T_NAMESPACE) && $next?->is([T_NAME_QUALIFIED, T_STRING])) {
                $namespace = $next->text.'\\';
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $next?->is(T_STRING) && !($tokens[$index - 1] ?? null)?->is(T_DOUBLE_COLON)) {
                $classes[] = $namespace.$next->text;
            }
        }

        return $classes;
    }
}
