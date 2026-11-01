<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\IsGrantedTool;
use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Mcp\Infrastructure\AuthorizedToolCalls;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(AuthorizedToolCalls::class)]
#[UsesClass(IsGrantedTool::class)]
#[UsesClass(ToolAccess::class)]
final class AuthorizedToolCallsTest extends TestCase
{
    public function testAGrantedToolRuns(): void
    {
        $handler = $this->handler(granted: true);

        self::assertSame('ran', $handler->handle(new ElementReference([WriteTool::class, '__invoke']), []));
    }

    public function testAClassLevelAttributeCountsForAnInvokableHandlerGivenByName(): void
    {
        $handler = $this->handler(granted: true);

        self::assertSame('ran', $handler->handle(new ElementReference(WriteTool::class), []));
    }

    public function testARefusedToolAnswersWithAnMcpErrorAndDoesNotRun(): void
    {
        $handler = $this->handler(granted: false);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This tool needs write access, which is not granted.');

        $handler->handle(new ElementReference([WriteTool::class, '__invoke']), []);
    }

    public function testAToolWithoutAnAccessLevelNeverRuns(): void
    {
        $handler = $this->handler(granted: true);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('declares no access level');

        $handler->handle(new ElementReference([UndeclaredTool::class, '__invoke']), []);
    }

    public function testTheVoterIsAskedAboutTheDeclaredAccessAndNothingElse(): void
    {
        $asked = [];
        $authorization = self::createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static function (mixed $attribute, mixed $subject = null) use (&$asked): bool {
            $asked[] = [$attribute, $subject];

            return true;
        });

        new AuthorizedToolCalls($this->inner(), $authorization)->handle(new ElementReference(WriteTool::class), ['allow_write' => true]);

        self::assertSame([[ToolAccess::Write, null]], $asked);
    }

    private function handler(bool $granted): AuthorizedToolCalls
    {
        $authorization = self::createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($granted);

        return new AuthorizedToolCalls($this->inner(), $authorization);
    }

    private function inner(): ReferenceHandlerInterface
    {
        $inner = self::createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn('ran');

        return $inner;
    }
}
