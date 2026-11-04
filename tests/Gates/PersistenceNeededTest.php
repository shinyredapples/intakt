<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class PersistenceNeededTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/persistence-needed.sh';
    private const string PERSISTENCE_TEST = "<?php\n#[Group('persistence')]\nfinal class OneTest\n{\n    public function testOne(): void\n    {\n    }\n}\n";
    private const string PLAIN_TEST = "<?php\nfinal class PlainTest\n{\n    public function testOne(): void\n    {\n    }\n}\n";

    public function testEditingTheBodyOfAPersistenceGroupTestNeedsTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        $sandbox->write('tests/Application/OneTest.php', str_replace('testOne', 'testTwo', self::PERSISTENCE_TEST));

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testAddingANewTestInThePersistenceGroupNeedsTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        $sandbox->write('tests/Application/NewTest.php', self::PERSISTENCE_TEST);

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testRemovingAPersistenceGroupTestNeedsTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        unlink($sandbox->root.'/tests/Application/OneTest.php');

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testChangingOnlyATestOutsideThePersistenceGroupDoesNotNeedTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        $sandbox->write('tests/Gates/PlainTest.php', str_replace('testOne', 'testTwo', self::PLAIN_TEST));

        self::assertSame('no', $this->answer($sandbox));
    }

    public function testAChangeUnderAnExistingPathTriggerStillNeedsTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        $sandbox->write('migrations/Version1.php', '<?php');

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testAnAddedDoctrineLineInSrcStillNeedsTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        $sandbox->write('src/Core/Other/Service.php', "<?php\nuse Doctrine\\DBAL\\Connection;\n");

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testAnUnrelatedSrcChangeDoesNotNeedTheEngines(): void
    {
        $sandbox = $this->sandboxWithTests();

        $sandbox->write('src/Core/Other/Service.php', "<?php\nfinal class Service\n{\n}\n");

        self::assertSame('no', $this->answer($sandbox));
    }

    public function testAnUnknownRevisionFailsInsteadOfAnsweringNo(): void
    {
        $sandbox = $this->sandboxWithTests();

        $result = $sandbox->runScript(escapeshellarg(self::SCRIPT).' nonexistent HEAD');

        self::assertFalse($result->succeeded());
        self::assertStringNotContainsString('no', trim($result->output));
    }

    private function sandboxWithTests(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('tests/Application/OneTest.php', self::PERSISTENCE_TEST);
        $sandbox->write('tests/Gates/PlainTest.php', self::PLAIN_TEST);
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
