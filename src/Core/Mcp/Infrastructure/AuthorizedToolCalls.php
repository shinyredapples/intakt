<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Closure;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionMethod;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

// The AI Bundle checks #[IsGrantedTool] only on its agent toolbox events, which MCP tool calls never pass, so this wrapper checks it for them.
final readonly class AuthorizedToolCalls implements ReferenceHandlerInterface
{
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private AuthorizationCheckerInterface $authorization,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        $checks = $this->declaredChecks($reference);

        // A tool that declares no check never runs, because an unlabeled tool could write.
        if ([] === $checks) {
            $this->logger->warning('Tool call {tool} refused: it declares no access level.', ['tool' => $this->name($reference)]);

            throw new ToolCallException('This tool declares no access level, so it is not available.');
        }

        foreach ($checks as $check) {
            $this->authorize($check, $reference);
        }

        // The arguments stay out of the log, because they are text a model chose.
        $this->logger->info('Tool call {tool} granted.', ['tool' => $this->name($reference), 'access' => $this->accesses($checks)]);

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

    private function name(ElementReference $reference): string
    {
        $handler = $reference->handler;

        return match (true) {
            is_array($handler) => (is_object($handler[0]) ? $handler[0]::class : $handler[0]).'::'.$handler[1],
            is_string($handler) => $handler,
            default => 'closure',
        };
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

    // A ToolCallException reaches the client as an MCP error result, because the SDK never sends its trace.
    private function authorize(IsGrantedTool $check, ElementReference $reference): void
    {
        // A subject would be built from the call's arguments, and a model must not argue its way to access.
        if (null !== $check->subject) {
            $this->logger->warning('Tool call {tool} refused: its access depends on its arguments.', ['tool' => $this->name($reference)]);

            throw new ToolCallException('This tool bases its access on its arguments, which Intakt never allows, so it is not available.');
        }

        if (!$this->authorization->isGranted($check->attribute)) {
            $this->logger->warning('Tool call {tool} refused: {access} is not granted.', ['tool' => $this->name($reference), 'access' => $this->accesses([$check])]);

            throw new ToolCallException(sprintf('This tool needs %s, which is not granted.', is_string($check->attribute) ? $check->attribute : 'its expression'));
        }
    }

    /**
     * @param list<IsGrantedTool> $checks
     */
    private function accesses(array $checks): string
    {
        return implode(',', array_map(static fn (IsGrantedTool $check): string => is_string($check->attribute) ? $check->attribute : 'expression', $checks));
    }
}
