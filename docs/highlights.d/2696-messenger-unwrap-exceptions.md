## Symfony components

| Messenger 8.2 bus option `unwrap_exceptions` | `command.bus` throws the failing handler's own exception to the code that dispatched the command instead of `HandlerFailedException`, so the dashboard's start of a review and the review command catch a refusal such as `RoundNotNext` by its type; `query.bus` and the workers still get `HandlerFailedException` | in progress | #2696 (part #1847), `config/packages/messenger.yaml` |
