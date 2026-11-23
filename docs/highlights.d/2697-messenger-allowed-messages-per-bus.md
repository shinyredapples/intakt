## Symfony components

| Messenger 8.2 bus option `messages` (allowed messages per bus) | `command.bus` accepts only `CommandMessage` and `query.bus` only `QueryMessage` (the platform module's marker interfaces), so a query dispatched on the command bus or a command on the query bus is refused with `InvalidArgumentException` before any handler runs; a message received from a transport (it carries a `ReceivedStamp`) bypasses this check, as Messenger documents | in progress | #2881 (part #2697), `config/packages/messenger.yaml` |
