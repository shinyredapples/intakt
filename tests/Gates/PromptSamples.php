<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

// shared by the two rules that guard prompt messages, so both analyze sample classes of the same shape
trait PromptSamples
{
    private const string SAMPLE_PARAMETERS = 'string $text, \Intakt\Core\Review\Contract\PromptAssembly $assembly, \Intakt\Core\Review\Contract\FencedText $body, \Symfony\AI\Platform\Result\ToolCall $call';

    private string $samplePath = '';

    /**
     * @var array<int, string>
     */
    private array $sampleCases = [];

    /**
     * @param list<string> $imports
     * @param list<string> $expressions
     */
    private function sampleSandbox(string $class, array $imports, array $expressions): Sandbox
    {
        $relative = str_replace('\\', '/', substr($class, strlen('Intakt\\'))).'.php';
        $this->samplePath = str_starts_with($relative, 'Tests/') ? 'tests/'.substr($relative, strlen('Tests/')) : 'src/'.$relative;

        foreach ($expressions as $index => $expression) {
            $this->sampleCases[12 + count($imports) + $index * 5] = $expression;
        }

        $sandbox = $this->emptySandbox();
        $sandbox->write($this->samplePath, $this->classReturning($class, $imports, $expressions));

        return $sandbox;
    }

    private function assertEachCaseRefused(string $findings, string $identifier): void
    {
        foreach ($this->sampleCases as $line => $expression) {
            self::assertStringContainsString($this->samplePath.':'.$line.' '.$identifier, $findings, $expression);
        }

        self::assertSame(count($this->sampleCases), substr_count($findings, $identifier), $findings);
    }

    /**
     * @param list<string> $imports
     * @param list<string> $expressions
     */
    private function classReturning(string $class, array $imports, array $expressions): string
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $name = substr($class, (int) strrpos($class, '\\') + 1);
        $uses = implode('', array_map(static fn (string $import): string => "use {$import};\n", $imports));
        $methods = array_map(
            static fn (int $index, string $expression): string => '    public function case'.$index.'('.self::SAMPLE_PARAMETERS."): mixed\n    {\n        return {$expression};\n    }\n",
            array_keys($expressions),
            $expressions,
        );

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$uses}\nfinal readonly class {$name}\n{\n".implode("\n", $methods)."}\n";
    }
}
