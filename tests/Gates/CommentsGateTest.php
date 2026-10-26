<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CommentsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CommentsGate::class)]
final class CommentsGateTest extends SandboxTestCase
{
    public function testAWhatCommentInAProjectFileFailsTheGate(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Total.php', "<?php\n\ndeclare(strict_types=1);\n\n// Adds the numbers\nfinal class Total\n{\n}\n");

        $run = $sandbox->check('--only=comments');

        self::assertTrue($run->failed('comments'), $run->output());
        self::assertStringContainsString('src/Total.php line 5', $run->findingsOf('comments'));
    }

    public function testAWhyCommentPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Total.php', "<?php\n\ndeclare(strict_types=1);\n\n// Integers only, because the ledger stores cents\nfinal class Total\n{\n}\n");

        self::assertTrue($sandbox->check('--only=comments')->passed('comments'));
    }
}
