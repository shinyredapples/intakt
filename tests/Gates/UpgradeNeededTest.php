<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class UpgradeNeededTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/upgrade-needed.sh';
    private const string MAPPED_CLASS = "<?php\n#[ORM\\Entity]\nfinal class Record {}\n";

    /** @return iterable<string, array{string, string, string}> */
    public static function changes(): iterable
    {
        yield 'a migration' => ['migrations/Version1.php', '<?php', 'yes'];
        yield 'the Dockerfile' => ['Dockerfile', 'FROM scratch', 'yes'];
        yield 'a script of the image' => ['docker/entrypoint.sh', '#!/bin/bash', 'yes'];
        yield 'the locked dependencies' => ['composer.lock', '{}', 'yes'];
        yield 'an edit of a mapped class' => ['src/Core/One/Domain/Record.php', self::MAPPED_CLASS."// edited\n", 'yes'];
        yield 'a new mapped class' => ['src/Core/Two/Domain/Other.php', self::MAPPED_CLASS, 'yes'];
        yield 'an unmapped class' => ['src/Core/One/Domain/Plain.php', "<?php\nfinal class Plain {}\n", 'no'];
        yield 'a guide page' => ['docs/guide/your-data.md', '# Your data', 'no'];
        yield 'composer.json alone' => ['composer.json', '{}', 'no'];
        yield 'a migration with a non-ASCII name' => ['migrations/Versión1.php', '<?php', 'yes'];
        yield 'a script of the image with a tab in its name' => ["docker/entry\tpoint.sh", '#!/bin/bash', 'yes'];
        yield 'a script of the image with a newline in its name' => ["docker/entry\npoint.sh", '#!/bin/bash', 'yes'];
        yield 'a mapped class with a non-ASCII name' => ['src/Core/Two/Domain/Récord.php', self::MAPPED_CLASS, 'yes'];
        yield 'a mapped class with a newline in its name' => ["src/Core/Two/Domain/Re\ncord.php", self::MAPPED_CLASS, 'yes'];
        yield 'a guide page with a non-ASCII name' => ['docs/guide/café.md', '# Café', 'no'];
        yield 'a guide page whose name has a watched folder after a newline' => ["docs/guide/a\ndocker/b.md", '# A', 'no'];
    }

    public function testAMigrationRenamedOutOfItsFolderStillNeedsTheCheck(): void
    {
        $sandbox = $this->sandboxWithAMappedClass();
        $sandbox->write('migrations/Version1.php', '<?php');
        $sandbox->commitAll('chore: add a migration');

        $sandbox->git('mv', 'migrations/Version1.php', 'Version1.php');

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testACutCharacterDoesNotHideTheNextPath(): void
    {
        $sandbox = $this->sandboxWithAMappedClass();
        $sandbox->write("docs/cut\xC2", 'docs');
        $sandbox->write('migrations/Version1.php', '<?php');
        $sandbox->commitAll('test: change');

        $result = $sandbox->runScript(escapeshellarg(self::SCRIPT).' HEAD~1 HEAD', ['LC_ALL' => 'C.UTF-8']);

        self::assertSame('yes', trim($result->output), $result->errors);
    }

    #[DataProvider('changes')]
    public function testTheAnswerSaysWhetherTheChangeTouchesWhatAnUpgradeStartsOn(string $path, string $contents, string $expected): void
    {
        $sandbox = $this->sandboxWithAMappedClass();

        $sandbox->write($path, $contents);

        self::assertSame($expected, $this->answer($sandbox));
    }

    public function testRemovingTheMappingFromAClassNeedsTheCheck(): void
    {
        $sandbox = $this->sandboxWithAMappedClass();

        $sandbox->write('src/Core/One/Domain/Record.php', "<?php\nfinal class Record {}\n");

        self::assertSame('yes', $this->answer($sandbox));
    }

    public function testAnUnknownRevisionFailsInsteadOfAnsweringNo(): void
    {
        $result = $this->sandboxWithAMappedClass()->runScript(escapeshellarg(self::SCRIPT).' nonexistent HEAD');

        self::assertFalse($result->succeeded());
        self::assertStringNotContainsString('no', trim($result->output));
    }

    private function sandboxWithAMappedClass(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('src/Core/One/Domain/Record.php', self::MAPPED_CLASS);
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
