## Symfony components

| DependencyInjection service decoration (`decoration_on_invalid: ignore`, `#[AutowireDecorated]`) | the project mode guard decorates whichever forge writes (the GitHub forge), lets only a live project's writes through (none of a shadow or reviewer-for-loop project), and drops out while no forge writes | in progress | T41 (#524), #1251 (part #1318), `src/Core/Shadow/Infrastructure/ModeGuardedForgeWrites.php` |
