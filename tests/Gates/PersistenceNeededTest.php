<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class PersistenceNeededTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/persistence-needed.sh';
    private const string PERSISTENCE_TEST = "<?php\n#[Group('persistence')]\nfinal class OneTest {}\n";

    /** @return iterable<string, array{string, string, string}> */
    public static function changes(): iterable
    {
        yield 'a body edit of a persistence test' => ['tests/Application/OneTest.php', self::PERSISTENCE_TEST."// edited\n", 'yes'];
        yield 'a new persistence test' => ['tests/Application/NewTest.php', self::PERSISTENCE_TEST, 'yes'];
        yield 'a test outside the group' => ['tests/Gates/PlainTest.php', "<?php\nfinal class PlainTest {}\n", 'no'];
        yield 'an existing path trigger' => ['migrations/Version1.php', '<?php', 'yes'];
        yield 'an added Doctrine line in src' => ['src/Core/Other/Service.php', "<?php\nuse Doctrine\\DBAL\\Connection;\n", 'yes'];
        yield 'an unrelated src change' => ['src/Core/Other/Service.php', "<?php\n", 'no'];
    }

    #[DataProvider('changes')]
    public function testTheAnswerSaysWhetherTheChangeTouchesPersistence(string $path, string $contents, string $expected): void
    {
        $sandbox = $this->sandboxWithTests();

        $sandbox->write($path, $contents);

        self::assertSame($expected, $this->answer($sandbox));
    }

    public function testRemovingAPersistenceGroupTestNeedsTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        unlink($sandbox->root.'/tests/Application/OneTest.php');

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testAnUnknownRevisionFailsInsteadOfAnsweringNo(): void
    {
        $result = $this->sandboxWithTests()->runScript(escapeshellarg(self::SCRIPT).' nonexistent HEAD');

        self::assertFalse($result->succeeded());
        self::assertStringNotContainsString('no', trim($result->output));
    }

    private function sandboxWithTests(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('tests/Application/OneTest.php', self::PERSISTENCE_TEST);
        $sandbox->commitAll('chore: start');

        return $sandbox;
    }

    private function answer(Sandbox $sandbox): string
    {
        $sandbox->commitAll('test: change');
        $result = $sandbox->runScript(escapeshellarg(self::SCRIPT).' HEAD~1 HEAD');

        self::assertTrue($result->succeeded(), $result->output.$result->errors);

        return trim($result->output);
    }
}
