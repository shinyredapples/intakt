<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

interface ParkedQuestions
{
    // Null without a stored row, and for a row outside the shape the parking writes, because a reader must never show a question it cannot vouch for.
    public function of(string $project, int $task): ?ParkedQuestion;
}
