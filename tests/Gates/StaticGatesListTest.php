<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckCommand::class)]
final class StaticGatesListTest extends TestCase
{
    public function testTheStaticGatesAreListedWithoutTheGatesThatRunTheChangesCode(): void
    {
        $run = $this->check('--static-gates');

        self::assertSame(0, $run->exitCode);
        self::assertMatchesRegularExpression('/^\{"static":\["cs","rector",.*"ratchet"\]\}\n$/', $run->output);
        self::assertDoesNotMatchRegularExpression('/"(tests|phpstan|container)"/', $run->output);
    }

    public function testTheStaticGatesListTakesNoOtherArgument(): void
    {
        $run = $this->check('--static-gates', '--fast');

        self::assertSame(2, $run->exitCode);
        self::assertStringContainsString('unknown argument --static-gates', $run->output);
    }

    private function check(string ...$arguments): ProcessResult
    {
        $home = dirname(__DIR__, 2);

        return new ProcessRunner()->run(['php', $home.'/bin/check', ...array_values($arguments)], $home);
    }
}
