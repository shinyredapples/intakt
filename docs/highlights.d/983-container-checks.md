## Tooling
| FrameworkBundle debug container dump | the `phpstan` gate of `bin/check` warms the `test` cache with debug on, and phpstan-symfony reads the dumped container XML to check service ids and parameter names and type `get()` and `getParameter()` | done | #983, `config/gates/src/ContainerDumpingGate.php` |
