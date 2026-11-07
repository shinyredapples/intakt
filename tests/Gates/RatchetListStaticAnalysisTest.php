<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

// Because the phpstan gate reads this repository's own configuration, the cases include it from a sandbox configuration that adds one list.
#[CoversNothing]
final class RatchetListStaticAnalysisTest extends TestCase
{
    private const string LOOSE_CONDITION = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class %s\n{\n    public function describe(int \$count): string\n    {\n        if (\$count) {\n            return 'some';\n        }\n\n        return 'none';\n    }\n}\n";

    public function testAnalysisWithARatchetListFails(): string
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('src/Listed.php', sprintf(self::LOOSE_CONDITION, 'Listed'));
        $sandbox->write('src/Unlisted.php', sprintf(self::LOOSE_CONDITION, 'Unlisted'));
        $sandbox->write('src/Stale.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Stale\n{\n}\n");

        $result = $this->analyseWithList($sandbox, ['Listed', 'Stale']);

        self::assertSame(1, $result->exitCode, $result->output.$result->errors);

        return $result->output;
    }

    #[Depends('testAnalysisWithARatchetListFails')]
    public function testAListedViolationPassesStaticAnalysis(string $report): void
    {
        self::assertStringNotContainsString('Listed.php', str_replace('Unlisted.php', '', $report));
    }

    #[Depends('testAnalysisWithARatchetListFails')]
    public function testAViolationNotInTheListFailsStaticAnalysis(string $report): void
    {
        self::assertStringContainsString('Unlisted.php', $report);
    }

    #[Depends('testAnalysisWithARatchetListFails')]
    public function testAnEntryThatMatchesNothingFailsStaticAnalysis(string $report): void
    {
        self::assertStringContainsString('Stale.php', $report);
        self::assertStringContainsString('was not matched', $report);
    }

    public function testAListedViolationInAMovedFileFailsStaticAnalysis(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('src/Moved.php', sprintf(self::LOOSE_CONDITION, 'Moved'));

        $result = $this->analyseWithList($sandbox, ['BeforeTheMove']);

        self::assertSame(1, $result->exitCode, $result->output.$result->errors);
        self::assertStringContainsString('src/BeforeTheMove.php" is neither a directory, nor a file path', $result->errors.$result->output);
    }

    /**
     * @param list<string> $listed
     */
    private function analyseWithList(Sandbox $sandbox, array $listed): ProcessResult
    {
        $home = dirname(__DIR__, 2);
        $sandbox->write('ratchet/loose-conditions.neon', $this->listOf($listed));
        $sandbox->write('phpstan.neon', "includes:\n    - ".$home."/config/gates/phpstan.neon\n    - ratchet/loose-conditions.neon\n");

        $result = new ProcessRunner()->run([$home.'/vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit=2G', '--configuration='.$sandbox->root.'/phpstan.neon', $sandbox->root.'/src'], $sandbox->root);
        $sandbox->remove();

        return $result;
    }

    /**
     * @param list<string> $classes
     */
    private function listOf(array $classes): string
    {
        $entries = array_map(
            static fn (string $class): string => "\t\t-\n\t\t\tmessage: '#^Only booleans are allowed in an if condition, int given\\.$#'\n\t\t\tidentifier: if.condNotBoolean\n\t\t\tcount: 1\n\t\t\tpath: ../src/".$class.".php\n",
            $classes,
        );

        return "# Cleanup issue: #436\nparameters:\n\tignoreErrors:\n".implode('', $entries);
    }
}
