## Tooling

| FrameworkBundle `debug:router --format=json` | the `routes` gate of `bin/check` reads the `prod` route collection and checks that each route is named `<module>_<use_case>` after its controller's folder | done | #3583, `config/gates/src/RouteNamesGate.php` |
