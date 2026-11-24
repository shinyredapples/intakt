<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\UseCase\ListProjects;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use LogicException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[McpTool(
    name: 'list_projects',
    description: 'List the projects Intakt is configured for, each with its name and whether it is a live project or a replay.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class ListProjectsTool
{
    public function __construct(
        private TierResolver $resolver,
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
    ) {
    }

    public function __invoke(): ProjectList
    {
        return new ProjectList(array_map($this->listed(...), [...$this->resolver->projects()]));
    }

    private function listed(string $project): ListedProject
    {
        $mark = $this->queries->dispatch(new ReplayMarkOfProject($project))->last(HandledStamp::class)?->getResult();

        return new ListedProject($project, $mark instanceof ReplayMark ? $mark : throw new LogicException('The replay mark query did not answer a mark.'));
    }
}
