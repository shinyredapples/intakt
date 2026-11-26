<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Recorded with the round's outcome, because the outcome reads the same on either path and only this tells plain code from a model call (D4 of #862).
enum VerdictPath: string
{
    case PlainCode = 'plain_code';
    case ModelCall = 'model_call';
}
