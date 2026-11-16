<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;

enum KeyKind: string
{
    case Anthropic = Provider::Anthropic->value;
    case OpenAi = Provider::OpenAi->value;
    case HuggingFace = 'huggingface';

    public static function fromForm(#[SensitiveParameter] string $key): ?self
    {
        // The OpenAI form excludes the Anthropic prefix, so a cut-off Anthropic key is refused instead of going to OpenAI.
        return match (true) {
            1 === preg_match('/^sk-ant-[A-Za-z0-9_-]{8,}$/D', $key) => self::Anthropic,
            1 === preg_match('/^sk-(?!ant-)[A-Za-z0-9_-]{8,}$/D', $key) => self::OpenAi,
            1 === preg_match('/^hf_[A-Za-z0-9]{8,}$/D', $key) => self::HuggingFace,
            default => null,
        };
    }
}
