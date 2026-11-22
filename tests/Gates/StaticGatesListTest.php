<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckCommand::class)]
#[UsesClass(ProcessRunner::class)]
final class StaticGatesListTest extends TestCase
{
    public function testTheListCarriesTheStaticGatesAndTheGateOrder(): void
    {
        $run = $this->check('--static-gates');

        self::assertSame(0, $run->exitCode);
        self::assertSame(['static', 'order'], array_keys($this->listIn($run)));
    }

    public function testTheStaticGatesLeaveOutTheGatesThatRunTheChangesCode(): void
    {
        $static = $this->namesIn($this->check('--static-gates'), 'static');

        self::assertSame(['environment', 'cs', 'rector', 'architecture', 'comments', 'audit', 'secrets', 'secrets-history', 'commits', 'dashes', 'highlights', 'reproductions', 'ratchet', 'workflows', 'left-undone'], $static);
    }

    public function testTheOrderNamesEveryGateInGateOrder(): void
    {
        $order = $this->namesIn($this->check('--static-gates'), 'order');

        self::assertSame(['environment', 'cs', 'phpstan', 'rector', 'architecture', 'comments', 'audit', 'tests', 'secrets', 'secrets-history', 'commits', 'dashes', 'highlights', 'reproductions', 'container', 'ratchet', 'workflows', 'left-undone'], $order);
    }

    public function testACodeGateSitsBetweenStaticGatesInTheOrder(): void
    {
        $run = $this->check('--static-gates');
        $static = $this->namesIn($run, 'static');
        $order = $this->namesIn($run, 'order');
        $phpstan = array_search('phpstan', $order, true);

        self::assertNotContains('phpstan', $static);
        self::assertIsInt($phpstan);
        self::assertContains($order[$phpstan - 1] ?? null, $static);
        self::assertContains($order[$phpstan + 1] ?? null, $static);
    }

    public function testTheStaticGatesListTakesNoOtherArgument(): void
    {
        $run = $this->check('--static-gates', '--fast');

        self::assertSame(2, $run->exitCode);
        self::assertStringContainsString('unknown argument --static-gates', $run->output);
    }

    /**
     * @return list<string>
     */
    private function namesIn(ProcessResult $run, string $key): array
    {
        $names = $this->listIn($run)[$key] ?? null;
        self::assertIsArray($names);

        return array_values(array_filter($names, is_string(...)));
    }

    private function check(string ...$arguments): ProcessResult
    {
        $home = dirname(__DIR__, 2);

        return new ProcessRunner()->run(['php', $home.'/bin/check', ...array_values($arguments)], $home);
    }

    /**
     * @return array<mixed>
     */
    private function listIn(ProcessResult $run): array
    {
        $list = json_decode($run->output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($list);

        return $list;
    }
}
