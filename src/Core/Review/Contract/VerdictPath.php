<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// How the Code reviewers' verdict step marked a round: in plain code when the reviewers agree, or by a model call for disagreements and deferrals (D4 of #862).
enum VerdictPath: string
{
    case PlainCode = 'plain_code';
    case ModelCall = 'model_call';
}
