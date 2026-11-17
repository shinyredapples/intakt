<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum StreamCutBy: string
{
    case Platform = 'platform';
    case ProviderFailureMidStream = 'provider-failure-mid-stream';
    case UnfinishedAnswer = 'unfinished-answer';
}
