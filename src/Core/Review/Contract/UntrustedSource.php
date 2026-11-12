<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum UntrustedSource: string
{
    case Task = 'task';
    case Diff = 'diff';
    case ToolResult = 'tool result';
    case EarlierFindings = 'earlier findings';
    case FindingsToJudge = 'findings to judge';
    case ProjectMemory = 'project memory';
    case Decisions = 'decisions';
    case ActList = 'act list';
    case CiLog = 'ci log';
    case PullRequestText = 'pull request text';
}
