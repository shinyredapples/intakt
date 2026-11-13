<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

enum HandBackRoute: string
{
    case SentBack = 'sent-back';
    case HandedOver = 'handed-over';
}
