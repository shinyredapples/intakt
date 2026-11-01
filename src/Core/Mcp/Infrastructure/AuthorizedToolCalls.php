<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Closure;
use Intakt\Core\Mcp\Contract\IsGrantedTool;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use ReflectionMethod;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

// A ToolCallException reaches the client as an MCP error result, because the SDK never sends its trace.
final readonly class AuthorizedToolCalls implements ReferenceHandlerInterface
{
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        // A tool that declares no access never runs, because an unlabeled tool could write.
        $declared = $this->declaredAccess($reference);

        if (!$declared instanceof IsGrantedTool) {
            throw new ToolCallException('This tool declares no access level, so it is not available.');
        }

        if (!$this->authorization->isGranted($declared->access)) {
            throw new ToolCallException(sprintf('This tool needs %s access, which is not granted.', $declared->access->value));
        }

        return $this->inner->handle($reference, $arguments);
    }

    private function declaredAccess(ElementReference $reference): ?IsGrantedTool
    {
        $method = $this->method($reference->handler);

        if (!$method instanceof ReflectionMethod) {
            return null;
        }

        $declared = array_first($method->getAttributes(IsGrantedTool::class))
            ?? array_first($method->getDeclaringClass()->getAttributes(IsGrantedTool::class));

        return $declared?->newInstance();
    }

    /**
     * @param Closure|array{0: object|string, 1: string}|string $handler
     */
    private function method(Closure|array|string $handler): ?ReflectionMethod
    {
        if (is_array($handler)) {
            return new ReflectionMethod($handler[0], $handler[1]);
        }

        if (is_string($handler) && class_exists($handler)) {
            return new ReflectionMethod($handler, '__invoke');
        }

        return null;
    }
}
