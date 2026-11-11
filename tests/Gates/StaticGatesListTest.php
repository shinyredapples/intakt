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
        $static = $this->listIn($this->check('--static-gates'))['static'];

        self::assertSame(['cs', 'rector', 'architecture', 'comments', 'audit', 'secrets', 'secrets-history', 'commits', 'dashes', 'highlights', 'reproductions', 'ratchet'], $static);
    }

    public function testTheOrderNamesEveryGateInGateOrder(): void
    {
        $order = $this->listIn($this->check('--static-gates'))['order'];

        self::assertSame(['cs', 'phpstan', 'rector', 'architecture', 'comments', 'audit', 'tests', 'secrets', 'secrets-history', 'commits', 'dashes', 'highlights', 'reproductions', 'container', 'ratchet'], $order);
    }

    public function testACodeGateSitsBetweenStaticGatesInTheOrder(): void
    {
        $list = $this->listIn($this->check('--static-gates'));
        $phpstan = array_search('phpstan', $list['order'], true);

        self::assertNotContains('phpstan', $list['static']);
        self::assertIsInt($phpstan);
        self::assertContains($list['order'][$phpstan - 1], $list['static']);
        self::assertContains($list['order'][$phpstan + 1], $list['static']);
    }

    public function testTheStaticGatesListTakesNoOtherArgument(): void
    {
        $run = $this->check('--static-gates', '--fast');

        self::assertSame(2, $run->exitCode);
        self::assertStringContainsString('unknown argument --static-gates', $run->output);
    }

    /**
     * @return array{static: list<string>, order: list<string>}
     */
    private function listIn(ProcessResult $run): array
    {
        /** @var array{static: list<string>, order: list<string>} */
        return json_decode($run->output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function check(string ...$arguments): ProcessResult
    {
        $home = dirname(__DIR__, 2);

        return new ProcessRunner()->run(['php', $home.'/bin/check', ...array_values($arguments)], $home);
    }
}
