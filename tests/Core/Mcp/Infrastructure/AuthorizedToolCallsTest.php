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
use Psr\Log\LoggerInterface;
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

        new AuthorizedToolCalls($this->inner(), $authorization, self::createStub(LoggerInterface::class))->handle(new ElementReference(WriteTool::class), ['allow_write' => true]);

        self::assertSame([['mcp_tool_write', null]], $asked);
    }

    public function testAGrantedCallIsLoggedWithItsToolAndAccessWithoutItsArguments(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Tool call {tool} granted.',
            self::callback(static fn (array $context): bool => [WriteTool::class.'::__invoke', 'mcp_tool_write'] === [$context['tool'], $context['access']] && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secret-argument')),
        );

        $this->handlerLogging($logger, ToolAccess::Write)->handle(new ElementReference([WriteTool::class, '__invoke']), ['note' => 'secret-argument']);
    }

    public function testARefusedCallIsLoggedAsRefusedAndTheToolDoesNotRun(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Tool call {tool} refused: {access} is not granted.',
            ['tool' => WriteTool::class.'::__invoke', 'access' => 'mcp_tool_write'],
        );
        $logger->expects(self::never())->method('info');

        $this->assertRefusedWith('This tool needs mcp_tool_write, which is not granted.', $this->handlerLogging($logger, ToolAccess::Read), WriteTool::class);
    }

    public function testAClosureToolIsLoggedAsRefusedForDeclaringNoAccessWithoutItsArguments(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Tool call {tool} refused: it declares no access level.', ['tool' => 'closure']);
        $logger->expects(self::never())->method('info');

        $this->assertReferenceRefusedWith(
            'This tool declares no access level, so it is not available.',
            $this->handlerLogging($logger, ToolAccess::Read, ToolAccess::Write),
            new ElementReference(static fn (): string => 'ran'),
        );
    }

    public function testAToolWhoseCheckReadsItsArgumentsIsLoggedAsRefusedWithoutItsArguments(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Tool call {tool} refused: its access depends on its arguments.',
            ['tool' => ArgumentCheckedTool::class.'::__invoke'],
        );
        $logger->expects(self::never())->method('info');

        $this->assertRefusedWith(
            'This tool bases its access on its arguments, which Intakt never allows, so it is not available.',
            $this->handlerLogging($logger, ToolAccess::Read, ToolAccess::Write),
            ArgumentCheckedTool::class,
        );
    }

    private function assertRefusedWith(string $message, AuthorizedToolCalls $handler, string $tool): void
    {
        $this->assertReferenceRefusedWith($message, $handler, new ElementReference([$tool, '__invoke']));
    }

    private function assertReferenceRefusedWith(string $message, AuthorizedToolCalls $handler, ElementReference $reference): void
    {
        try {
            $handler->handle($reference, ['project' => 'secret-argument']);
        } catch (ToolCallException $refusal) {
            self::assertSame($message, $refusal->getMessage());

            return;
        }

        self::fail('The tool ran.');
    }

    private function handler(ToolAccess $first, ToolAccess ...$granted): AuthorizedToolCalls
    {
        return $this->handlerLogging(self::createStub(LoggerInterface::class), $first, ...$granted);
    }

    private function handlerLogging(LoggerInterface $logger, ToolAccess ...$granted): AuthorizedToolCalls
    {
        $values = array_map(static fn (ToolAccess $access): string => $access->value, $granted);
        $authorization = self::createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => in_array($attribute, $values, true));

        return new AuthorizedToolCalls($this->inner(), $authorization, $logger);
    }

    private function inner(): ReferenceHandlerInterface
    {
        $inner = self::createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn('ran');

        return $inner;
    }
}
