<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

use LogicException;

final class ProjectMemoryNeedsEmbeddingModel extends LogicException
{
    public function __construct()
    {
        parent::__construct('Project memory needs an embedding model: enable an OpenAI account (set OPENAI_API_KEY) to index it.');
    }
}
