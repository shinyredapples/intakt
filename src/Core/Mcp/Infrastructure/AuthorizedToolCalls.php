<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Closure;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use ReflectionAttribute;
use ReflectionMethod;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

// The AI Bundle checks #[IsGrantedTool] only on its agent toolbox events, which MCP tool calls never pass, so this wrapper checks it for them.
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
        $checks = $this->declaredChecks($reference);

        // A tool that declares no check never runs, because an unlabeled tool could write.
        if ([] === $checks) {
            throw new ToolCallException('This tool declares no access level, so it is not available.');
        }

        foreach ($checks as $check) {
            $this->authorize($check);
        }

        return $this->inner->handle($reference, $arguments);
    }

    /**
     * @return list<IsGrantedTool>
     */
    private function declaredChecks(ElementReference $reference): array
    {
        $method = $this->method($reference->handler);

        if (!$method instanceof ReflectionMethod) {
            return [];
        }

        $attributes = [
            ...$method->getDeclaringClass()->getAttributes(IsGrantedTool::class),
            ...$method->getAttributes(IsGrantedTool::class),
        ];

        return array_map(static fn (ReflectionAttribute $attribute): IsGrantedTool => $attribute->newInstance(), $attributes);
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

    private function authorize(IsGrantedTool $check): void
    {
        // A subject would be built from the call's arguments, and a model must not argue its way to access.
        if (null !== $check->subject) {
            throw new ToolCallException('This tool bases its access on its arguments, which Intakt never allows, so it is not available.');
        }

        if (!$this->authorization->isGranted($check->attribute)) {
            throw new ToolCallException(sprintf('This tool needs %s, which is not granted.', $check->attribute));
        }
    }
}
