<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Upstream;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class StructuredOutputReproductionTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    public function testAnInvalidAnswerBesideThinkingSkipsTheValidatorWhileTheSameAnswerAloneIsRefused(): void
    {
        $display = $this->reproductionOutput();

        self::assertStringContainsString('control: an invalid answer alone: refused by the Validator', $display);
        self::assertStringContainsString('B: an invalid answer beside thinking: a array instead of an Answer', $display);
    }

    public function testTheAnswerBesideThinkingIsNotReturnedAsTheTypedObject(): void
    {
        self::assertStringContainsString('B: the answer beside thinking: a array instead of an Answer', $this->reproductionOutput());
    }

    private function reproductionOutput(): string
    {
        $process = new Process([
            PHP_BINARY,
            '-r',
            'require $argv[1]; require $argv[2];',
            self::ROOT.'/vendor/autoload.php',
            self::ROOT.'/upstream/ai-structured-output-multi-part-answers/reproduce.php',
        ]);
        $process->run();

        return $process->getOutput();
    }
}
