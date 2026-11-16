<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// The message is fixed and never carries the input, not even a prefix, so a refused key cannot reach a log.
final class KeyNotRecognised extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The key is not an Anthropic key, an OpenAI key or a Hugging Face token.');
    }
}
