<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// The router provider a model id pins after its colon (`deepinfra` in `moonshotai/Kimi-K3.1-Pro:deepinfra`), on the account the call runs on, because one account's limit at that provider leaves another account free.
final readonly class PinnedProvider
{
    // Only a router's `owner/model:provider` shape pins: exactly one slash and one colon, each part only visible ASCII other than the slash and the colon, anchored at the very end, so a native id with colons (`ft:gpt-4o-mini:org::id`), a tag without an owner (`llama3:8b`), a nested owner (`owner/group/model:router`) or a name with whitespace, a control character or any non-ASCII character pins nothing.
    private const string PINNED = '~^([\x21-\x2E\x30-\x39\x3B-\x7E]+/[\x21-\x2E\x30-\x39\x3B-\x7E]+):([\x21-\x2E\x30-\x39\x3B-\x7E]+)\z~';

    public function __construct(
        public Account $account,
        public string $routerProvider,
    ) {
    }

    // A colon joins them because an account key (provider value and NAME_PATTERN) cannot hold one, so the first colon always ends the account.
    public function key(): string
    {
        return $this->account->key().':'.$this->routerProvider;
    }

    // The model and the router provider it pins, read without the options after a question mark, because Symfony AI's catalog takes them from the model name too.
    /**
     * @return array{string, string}|null
     */
    public static function parts(string $model): ?array
    {
        return 1 === preg_match(self::PINNED, explode('?', $model, 2)[0], $pin) ? [$pin[1], $pin[2]] : null;
    }
}
