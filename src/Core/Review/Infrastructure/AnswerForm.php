<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

enum AnswerForm: string
{
    case Json = 'json';

    case ProseAroundBlock = 'prose-around-block';
}
