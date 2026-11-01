<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Tests\Plugin\SymfonyAiVendor\RecordedClaudeCodeCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;

#[CoversClass(FinishedProcessResult::class)]
final class FinishedProcessResultTest extends TestCase
{
    public function testACompleteStreamGivesItsFinalResult(): void
    {
        $data = $this->finished(RecordedClaudeCodeCli::replaying('complete'))->getData();

        self::assertSame('result', $data['type']);
    }

    public function testTheToolCallsTheCliAlreadyRanAreNotHandedOn(): void
    {
        $data = $this->finished(RecordedClaudeCodeCli::replaying('tool-use'))->getData();

        self::assertSame('result', $data['type']);
        self::assertArrayNotHasKey('tool_calls', $data);
    }

    public function testAStreamThatEndsWithoutItsFinalResultIsCutOff(): void
    {
        $this->expectException(IncompleteStreamException::class);
        $this->expectExceptionMessage('ended before its final result: the stream ended.');

        $this->finished(RecordedClaudeCodeCli::replaying('truncated'))->getData();
    }

    public function testACliThatExitsMidStreamIsCutOff(): void
    {
        $this->expectException(IncompleteStreamException::class);
        $this->expectExceptionMessage('exit code 143');

        $this->finished(RecordedClaudeCodeCli::exitingAfter('truncated', 143))->getData();
    }

    public function testACliKilledMidStreamIsCutOff(): void
    {
        $this->expectException(IncompleteStreamException::class);
        $this->expectExceptionMessage('the CLI was stopped by signal 15');

        $this->finished(RecordedClaudeCodeCli::killedAfter('truncated'))->getData();
    }

    public function testACliThatTimesOutMidStreamIsCutOff(): void
    {
        $this->expectException(IncompleteStreamException::class);
        $this->expectExceptionMessage('the CLI timed out');

        $this->finished(RecordedClaudeCodeCli::stalledAfter('truncated'))->getData();
    }

    public function testACliThatFailsAfterItsFinalResultStaysAFailure(): void
    {
        try {
            $this->finished(RecordedClaudeCodeCli::exitingAfter('failed', 1))->getData();
        } catch (RuntimeException $failure) {
            self::assertNotInstanceOf(IncompleteStreamException::class, $failure);
            self::assertStringContainsString('Not logged in', $failure->getMessage());

            return;
        }

        self::fail('A failed CLI run gave a result.');
    }

    private function finished(RecordedClaudeCodeCli $cli): FinishedProcessResult
    {
        return new FinishedProcessResult($cli->request(new ClaudeCode('sonnet'), 'Review.'));
    }
}
