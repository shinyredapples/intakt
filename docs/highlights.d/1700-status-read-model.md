## Symfony components

| DependencyInjection `#[AutowireIterator]` with `indexAttribute` and Messenger `MessageCountAwareInterface` | the status read model counts the messages waiting in each Messenger transport, the transports collected by their `messenger.receiver` tag and named by its `alias`, so a stopped consumer shows as messages piling up instead of as no status | in progress | T68 (#350, part #1700), `src/Core/Dashboard/Infrastructure/MessageQueues.php` |
