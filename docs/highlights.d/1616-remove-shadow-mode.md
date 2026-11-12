## Symfony components

| DependencyInjection service decoration (`decoration_on_invalid: ignore`, `#[AutowireDecorated]`) | no longer used by the application: the project mode guard that decorated the forge writes went with the shadow mode, so the container's forge writes are the GitHub forge itself; only the test container still decorates services (the logger and the live-update hub) | done | reviewer for the loop (#1251, parts #1318 and #1319), #1433 (part #1478), #1434, `config/services.yaml` |
