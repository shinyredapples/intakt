<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Mcp\Infrastructure\AuthorizedToolCalls;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(AuthorizedToolCalls::class)]
#[UsesClass(IsGrantedTool::class)]
#[UsesClass(ToolAccess::class)]
final class AuthorizedToolCallsTest extends TestCase
{
    public function testAGrantedToolRuns(): void
    {
        $handler = $this->handler(ToolAccess::Read, ToolAccess::Write);

        self::assertSame('ran', $handler->handle(new ElementReference([WriteTool::class, '__invoke']), []));
    }

    public function testAClassLevelAttributeCountsForAnInvokableHandlerGivenByName(): void
    {
        $handler = $this->handler(ToolAccess::Read, ToolAccess::Write);

        self::assertSame('ran', $handler->handle(new ElementReference(WriteTool::class), []));
    }

    public function testARefusedToolAnswersWithAnMcpErrorAndDoesNotRun(): void
    {
        $handler = $this->handler(ToolAccess::Read);

        $this->assertRefusedWith('This tool needs mcp_tool_write, which is not granted.', $handler, WriteTool::class);
    }

    public function testEveryDeclaredCheckMustBeGranted(): void
    {
        $handler = $this->handler(ToolAccess::Read);

        $this->assertRefusedWith('This tool needs mcp_tool_write, which is not granted.', $handler, ReadThenWriteTool::class);
    }

    public function testAToolWithoutAnAccessLevelNeverRuns(): void
    {
        $handler = $this->handler(ToolAccess::Read, ToolAccess::Write);

        $this->assertRefusedWith('This tool declares no access level, so it is not available.', $handler, UndeclaredTool::class);
    }

    public function testAToolWhoseCheckReadsItsArgumentsNeverRuns(): void
    {
        $handler = $this->handler(ToolAccess::Read, ToolAccess::Write);

        $this->assertRefusedWith(
            'This tool bases its access on its arguments, which Intakt never allows, so it is not available.',
            $handler,
            ArgumentCheckedTool::class,
        );
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

        self::assertSame([['mcp_tool_write', null]], $asked);
    }

    private function assertRefusedWith(string $message, AuthorizedToolCalls $handler, string $tool): void
    {
        try {
            $handler->handle(new ElementReference([$tool, '__invoke']), ['project' => 'any']);
        } catch (ToolCallException $refusal) {
            self::assertSame($message, $refusal->getMessage());

            return;
        }

        self::fail('The tool ran.');
    }

    private function handler(ToolAccess ...$granted): AuthorizedToolCalls
    {
        $values = array_map(static fn (ToolAccess $access): string => $access->value, $granted);
        $authorization = self::createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => in_array($attribute, $values, true));

        return new AuthorizedToolCalls($this->inner(), $authorization);
    }

    private function inner(): ReferenceHandlerInterface
    {
        $inner = self::createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn('ran');

        return $inner;
    }
}
