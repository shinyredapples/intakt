<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(GateResult::class)]
final class GateResultShortenTest extends TestCase
{
    public function testALongFindingIsShortenedWithoutMbstring(): void
    {
        $finding = $this->shortenedWithoutMbstring(str_repeat('é', 400));

        self::assertSame(GateResult::MAXIMUM_FINDING_LENGTH, mb_strlen($finding));
        self::assertStringEndsWith('...', $finding);
    }

    public function testAShortFindingIsKeptWithoutMbstring(): void
    {
        self::assertSame('a b', $this->shortenedWithoutMbstring("a\n b"));
    }

    private function shortenedWithoutMbstring(string $finding): string
    {
        $process = new Process([
            PHP_BINARY,
            '-d',
            'disable_functions=mb_strimwidth',
            '-r',
            'require $argv[1]; echo json_decode(Intakt\Gates\GateResult::withFindings("x", [$argv[2]])->toJson(), true)["findings"][0];',
            dirname(__DIR__, 2).'/vendor/autoload.php',
            $finding,
        ]);
        $process->mustRun();

        return $process->getOutput();
    }
}
